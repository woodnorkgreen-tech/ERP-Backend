<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ProcurementStores\Requests\StockMovementRequest;
use App\Modules\ProcurementStores\Services\StockMovementPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

        $logs = [];
        $boards = [];
        $batchNumber = null;

        try {
            DB::transaction(function () use ($type, $lines, &$logs, &$boards, &$batchNumber) {
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
                        throw ValidationException::withMessages(
                            collect($e->errors())
                                ->mapWithKeys(fn ($messages, $field) => ["lines.{$index}.".$this->fieldOf($field) => $messages])
                                ->all(),
                        );
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
            });
        } catch (\DomainException $e) {
            // Anything raised outside the per-line loop. Kept so a rule about
            // the data never surfaces as a server fault.
            return response()->json(['message' => $e->getMessage(), 'status' => 'error'], 422);
        }

        return response()->json([
            'message' => $this->summary($type, count($logs), count($boards)),
            'status' => 'success',
            'batch_number' => $batchNumber,
            'lines_posted' => count($logs),
            'data' => $logs,
            'boards_created' => count($boards),
            // Boards are not shelved until their labels are printed and stuck
            // on, so the screen has to know to ask before it clears the form.
            'labels_required' => count($boards) > 0,
            'boards' => $this->describeBoards($boards),
        ]);
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
