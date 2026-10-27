<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\StockMovementSubmission;
use App\Modules\ProcurementStores\Requests\StockMovementRequest;
use App\Modules\ProcurementStores\Services\StockMovementPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One endpoint for every stock movement.
 *
 * Receiving, issuing, returning and writing off are the same act with a
 * different sign and a different set of questions, so they are one route with a
 * type rather than four routes with four half-matching contracts. A movement of
 * one line and a movement of forty are the same request; only `lines` is longer.
 *
 * The whole posting is one transaction. Receiving a delivery of twelve items is
 * one event to the person doing it, and a half-posted delivery — six lines in,
 * six lines rejected, no record of which — is the state that made people count
 * the shelf twice.
 */
class StockMovementController extends Controller
{
    public function __construct(private readonly StockMovementPoster $poster)
    {
    }

    public function store(StockMovementRequest $request): JsonResponse
    {
        $type = $request->movementType();
        $lines = $request->lines();

        /*
         * Report 79E (P0-2): a resend after a timeout used to move the stock a
         * second time. A screen that sends a client_request_id (one per press
         * of Confirm, reused on retry) gets the first answer back instead.
         */
        $clientRequestId = $request->input('client_request_id');
        $userId = (int) auth()->id();
        $hash = $clientRequestId ? StockMovementSubmission::hashOf($type, $lines) : null;
        if ($clientRequestId && ($prior = $this->submission($userId, $clientRequestId))) {
            return $this->replay($prior, $hash);
        }

        $logs = [];
        $boards = [];
        $batchNumber = null;
        $submission = null;

        try {
            DB::transaction(function () use ($type, $lines, $clientRequestId, $userId, $hash, &$logs, &$boards, &$batchNumber, &$submission) {
                // Reset per attempt: a retried attempt starts from nothing.
                $logs = [];
                $boards = [];
                $submission = null;

                if ($clientRequestId) {
                    // First write, inside the movement's transaction: it commits
                    // with the stock or not at all, and a concurrent resend waits
                    // here on the unique key, then fails and replays.
                    $submission = StockMovementSubmission::create([
                        'user_id' => $userId, 'client_request_id' => $clientRequestId,
                        'movement_type' => $type, 'payload_hash' => $hash,
                    ]);
                }

                // Every line of one posting carries the same batch number, so the
                // ledger can show a delivery as the single event it was.
                $batchNumber = $this->poster->newBatchNumber();

                foreach ($lines as $index => $line) {
                    try {
                        $result = $this->poster->post($type, array_merge($line, ['batch_number' => $batchNumber]));
                    } catch (ValidationException $e) {
                        // Name the line that failed. Without this a fifteen-line
                        // receipt answers "quantity is required" and the person
                        // has to guess which row it meant.
                        $errors = collect($e->errors())
                            ->mapWithKeys(fn ($messages, $field) => ["lines.{$index}.".$this->fieldOf($field) => $messages])
                            ->all();
                        $renamed = ValidationException::withMessages($errors);
                        // Report 79C: keep the duplicate gate's verdict (code and
                        // candidates) so the receiving screen can offer them.
                        $body = $e->response?->getData(true) ?? [];
                        if (isset($body['code'])) {
                            $renamed->response = response()->json([
                                'message' => $body['message'] ?? '', 'code' => $body['code'],
                                'candidates' => $body['candidates'] ?? [], 'line' => $index, 'errors' => $errors,
                            ], 422);
                        }
                        throw $renamed;
                    } catch (\DomainException $e) {
                        /*
                         * Refusals raised deeper than validation — an unfinished
                         * catalogue item, or a cost the collector cannot
                         * classify. They arrive as a bare sentence about the
                         * movement, so they are re-raised against the line that
                         * caused them; otherwise a twelve-line posting reports
                         * one unattributed sentence and the person has to bisect
                         * their own delivery to find the row.
                         */
                        throw ValidationException::withMessages([
                            "lines.{$index}.material_id" => [$e->getMessage()],
                        ]);
                    }

                    $logs[] = $result['log'];
                    $boards = array_merge($boards, $result['boards']);
                }

                $submission?->update(['batch_number' => $batchNumber, 'log_ids' => array_map(fn ($log) => $log->id, $logs)]);
            }, attempts: 3);
            // attempts: a collision with another Stores user's issue (deadlock,
            // or MariaDB's "record has changed since last read") rolls back and
            // re-runs against the committed state, so the second user gets the
            // real answer ("only N remaining") instead of a server error.
        } catch (\DomainException $e) {
            // Anything raised outside the per-line loop. Kept so a rule about
            // the data never surfaces as a server fault.
            return response()->json(['message' => $e->getMessage(), 'status' => 'error'], 422);
        } catch (QueryException $e) {
            // A concurrent resend of the same confirmation committed first.
            if ($clientRequestId && (int) ($e->errorInfo[1] ?? 0) === 1062
                && ($prior = $this->submission($userId, $clientRequestId))) {
                return $this->replay($prior, $hash);
            }
            throw $e;
        }

        $payload = $this->respond($type, $logs, $boards, $batchNumber);
        // Saved after commit. If the process dies before this, a replay rebuilds
        // the answer from the movement ids written inside the transaction.
        $submission?->forceFill(['response' => $payload])->save();

        return response()->json($payload);
    }

    private function submission(int $userId, string $clientRequestId): ?StockMovementSubmission
    {
        return StockMovementSubmission::where('user_id', $userId)->where('client_request_id', $clientRequestId)->first();
    }

    /**
     * The first answer to this confirmation, with Finance's current posting
     * status (it may have been retried since). Nothing moves.
     */
    private function replay(StockMovementSubmission $prior, ?string $hash): JsonResponse
    {
        if (! hash_equals($prior->payload_hash, (string) $hash)) {
            return response()->json([
                'message' => 'This confirmation was already used for different lines or quantities, so nothing was issued. Review the selection and confirm again.',
                'status' => 'error',
                'code' => 'REQUEST_REUSED',
            ], 409);
        }

        $logs = InventoryLog::whereIn('id', $prior->log_ids ?? [])->orderBy('id')->get()->all();
        $payload = $this->respond($prior->movement_type, $logs, [], $prior->batch_number);
        foreach (['boards_created', 'labels_required', 'boards'] as $key) {
            if (isset($prior->response[$key])) {
                $payload[$key] = $prior->response[$key];
            }
        }
        $payload['replayed'] = true;
        $payload['message'] = 'Already recorded — nothing was moved again. '.$payload['message'];

        return response()->json($payload);
    }

    private function respond(string $type, array $logs, array $boards, ?string $batchNumber): array
    {
        // Report 79D: the transaction has committed and the outbox has run, so
        // say what Finance actually did with each line instead of assuming it.
        $posting = app(\App\Modules\ProcurementStores\Services\StoresPostingResult::class)
            ->forLogs(array_map(fn ($log) => $log->id, $logs));
        $postingSentence = in_array($type, ['issue', 'return'], true)
            ? app(\App\Modules\ProcurementStores\Services\StoresPostingResult::class)->sentence($posting['summary'])
            : '';

        return [
            'message' => trim($this->summary($type, count($logs), count($boards)).' '.$postingSentence),
            'status' => 'success',
            'batch_number' => $batchNumber,
            'lines_posted' => count($logs),
            // Per line: posted (cost line + journal), failed (reason), pending,
            // not_applicable or none. Stock moved in every case.
            'posting' => $posting['lines'],
            'posting_summary' => $posting['summary'],
            'data' => $logs,
            'controlled_units' => \App\Modules\ProcurementStores\Models\ConsumableUnit::with('material')->whereIn('source_log_id', array_map(fn ($log) => $log->id, $logs))->orWhereIn('id', array_values(array_filter(array_map(fn ($log) => $log->type === 'return' ? $log->consumable_unit_id : null, $logs))))->get(),
            'boards_created' => count($boards),
            // Boards are not shelved until their labels are printed and stuck
            // on, so the screen has to know to ask before it clears the form.
            'labels_required' => count($boards) > 0,
            'boards' => $this->describeBoards($boards),
            'replayed' => false,
        ];
    }

    /** Strip any `lines.N.` the poster's own message may already carry. */
    private function fieldOf(string $field): string
    {
        return preg_replace('/^lines\.\d+\./', '', $field) ?? $field;
    }

    private function summary(string $type, int $lines, int $boards): string
    {
        $noun = $lines === 1 ? 'line' : 'lines';
        $verb = match ($type) {
            'receive' => 'received',
            'issue' => 'issued',
            'return' => 'returned to stock',
            'damage' => 'written off',
        };
        $message = "{$lines} {$noun} {$verb}.";

        return $boards > 0 ? "{$message} {$boards} board records created — labels still to print." : $message;
    }

    /**
     * A receipt can create a hundred boards across a handful of materials, so
     * the names are loaded once for the whole set rather than per board.
     *
     * @param  array<int, \App\Modules\ProcurementStores\Models\Board>  $boards
     */
    private function describeBoards(array $boards): array
    {
        $collection = (new EloquentCollection($boards))->loadMissing('libraryMaterial');
        $base = config('app.frontend_url', config('app.url'));

        return $collection->map(fn ($board) => [
            'id' => $board->id,
            'tracking_code' => $board->tracking_code,
            'scan_url' => $base.'/stores/boards/'.$board->tracking_code,
            'length' => $board->length,
            'width' => $board->width,
            'thickness' => $board->thickness,
            'batch_number' => $board->batch_number,
            'material' => [
                'name' => $board->libraryMaterial?->material_name,
                'code' => $board->libraryMaterial?->material_code,
            ],
        ])->all();
    }
}
