<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Create payment_methods table first
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('method_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Insert default payment methods
        DB::table('payment_methods')->insert([
            ['method_name' => 'Bank Transfer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['method_name' => 'Cash', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['method_name' => 'Check', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['method_name' => 'Credit Card', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['method_name' => 'Mobile Money', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Step 2: Rename invoices table to bills
        Schema::rename('invoices', 'bills');

        // Step 3: Modify bills table - rename columns
        Schema::table('bills', function (Blueprint $table) {
            // Rename invoice_number to bill_number
            $table->renameColumn('invoice_number', 'bill_number');
            // Rename invoice_date to bill_date
            $table->renameColumn('invoice_date', 'bill_date');
        });

        // Step 4: Add new columns to bills table
        Schema::table('bills', function (Blueprint $table) {
            $table->decimal('paid_amount', 15, 2)->default(0)->after('amount');
            $table->decimal('balance', 15, 2)->default(0)->after('paid_amount');
        });

        // Step 5: Update existing records - set balance = amount and paid_amount = 0 for all existing bills
        DB::table('bills')->update([
            'paid_amount' => 0,
            'balance' => DB::raw('amount')
        ]);

        // Step 6: Update status for paid bills (those with payment_date)
        DB::table('bills')
            ->whereNotNull('payment_date')
            ->update([
                'paid_amount' => DB::raw('amount'),
                'balance' => 0,
                'status' => 'paid'
            ]);

        // Step 7: Add 'partial' to status enum
        DB::statement("ALTER TABLE bills MODIFY COLUMN status ENUM('pending', 'paid', 'partial', 'overdue', 'cancelled') DEFAULT 'pending'");

        // Step 8: Create bill_payments table
        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_code')->unique();
            $table->unsignedBigInteger('bill_id');
            $table->decimal('amount_paid', 15, 2);
            $table->date('payment_date');
            $table->unsignedBigInteger('payment_method_id');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            
            $table->foreign('bill_id')->references('id')->on('bills')->onDelete('cascade');
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Step 9: Migrate existing payment data to bill_payments table
        // For bills that have payment_date and payment_method, create a payment record
        $paidBills = DB::table('bills')
            ->whereNotNull('payment_date')
            ->whereNotNull('payment_method')
            ->get();

        foreach ($paidBills as $bill) {
            // Find or create payment method
            $methodName = $bill->payment_method;
            $paymentMethod = DB::table('payment_methods')
                ->where('method_name', $methodName)
                ->first();

            if (!$paymentMethod) {
                // Create new payment method if it doesn't exist
                $methodId = DB::table('payment_methods')->insertGetId([
                    'method_name' => $methodName,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } else {
                $methodId = $paymentMethod->id;
            }

            // Generate payment code
            $lastPayment = DB::table('bill_payments')->orderBy('id', 'desc')->first();
            $number = $lastPayment ? intval(substr($lastPayment->payment_code, 4)) + 1 : 1;
            $paymentCode = 'PAY-' . str_pad($number, 6, '0', STR_PAD_LEFT);

            // Create payment record
            DB::table('bill_payments')->insert([
                'payment_code' => $paymentCode,
                'bill_id' => $bill->id,
                'amount_paid' => $bill->amount,
                'payment_date' => $bill->payment_date,
                'payment_method_id' => $methodId,
                'notes' => null,
                'user_id' => $bill->user_id,
                'created_at' => $bill->updated_at ?? now(),
                'updated_at' => $bill->updated_at ?? now()
            ]);
        }
    }

    public function down(): void
    {
        // Reverse the changes
        
        // Drop bill_payments table
        Schema::dropIfExists('bill_payments');

        // Remove new columns from bills
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'balance']);
        });

        // Revert status enum
        DB::statement("ALTER TABLE bills MODIFY COLUMN status ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending'");

        // Rename columns back
        Schema::table('bills', function (Blueprint $table) {
            $table->renameColumn('bill_number', 'invoice_number');
            $table->renameColumn('bill_date', 'invoice_date');
        });

        // Rename table back to invoices
        Schema::rename('bills', 'invoices');

        // Drop payment_methods table
        Schema::dropIfExists('payment_methods');
    }
};