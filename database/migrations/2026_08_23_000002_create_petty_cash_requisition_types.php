<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_requisition_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 100)->unique();
            $table->string('description')->nullable();
            $table->string('icon', 64)->default('mdi-cash-multiple');
            $table->string('recipient_mode', 24)->default('single');
            $table->boolean('requires_project')->default(false);
            $table->json('request_fields')->nullable();
            $table->json('item_fields')->nullable();
            $table->json('instructions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->foreignId('requisition_type_id')->nullable()->after('category')
                ->constrained('petty_cash_requisition_types')->nullOnDelete();
            $table->json('custom_fields')->nullable()->after('purpose');
            $table->json('type_snapshot')->nullable()->after('custom_fields');
        });
        Schema::table('petty_cash_requisition_items', function (Blueprint $table) {
            $table->json('details')->nullable()->after('remarks');
        });

        $now = now();
        $types = [
            ['projects','Projects','Project activity, site work or technical labour.','mdi-briefcase-check-outline','per_item',true,
                [['key'=>'activity_date','label'=>'Activity date','type'=>'date','required'=>true],['key'=>'site_contact','label'=>'Site contact','type'=>'text']],
                [['key'=>'work_scope','label'=>'Work or deliverable','type'=>'text','required'=>true]],
                ['Link the correct project or enquiry.','Name each recipient and the work being paid for.']],
            ['office_supplies','Office Supplies','Low-value consumables needed for office operations.','mdi-pencil-outline','single',false,[],
                [['key'=>'quantity','type'=>'number','required'=>true,'min'=>1],['key'=>'specification','type'=>'text']],
                ['List each item and quantity.','Use Procurement for planned or high-value purchases.']],
            ['transport','Transport','Local travel, delivery or fare reimbursement.','mdi-car-outline','per_item',false,
                [['key'=>'travel_date','type'=>'date','required'=>true],['key'=>'journey_purpose','type'=>'text','required'=>true]],
                [['key'=>'origin','type'=>'text','required'=>true],['key'=>'destination','type'=>'text','required'=>true]],
                ['Enter the route and recipient for every fare.']],
            ['meals','Meals','Meals or refreshments for an approved activity.','mdi-food-outline','per_item',false,
                [['key'=>'service_date','label'=>'Meal date','type'=>'date','required'=>true],['key'=>'meal_type','type'=>'select','required'=>true,'options'=>['Breakfast','Lunch','Dinner','Refreshments']]],
                [],['State the event or work activity.','Name recipients or use one line for a documented group.']],
            ['repair_maintenance','Repair & Maintenance','Urgent minor repair or maintenance expense.','mdi-wrench-outline','single',false,
                [['key'=>'asset_or_location','label'=>'Asset or location','type'=>'text','required'=>true],['key'=>'fault','type'=>'textarea','required'=>true]],
                [['key'=>'quantity','type'=>'number','min'=>1]],['Describe the fault before listing the repair inputs.']],
            ['fuel_lubricants','Fuel & Lubricants','Fuel or lubricant purchase for an identified asset.','mdi-gas-station-outline','single',false,
                [['key'=>'vehicle_or_asset','type'=>'text','required'=>true],['key'=>'odometer_or_hours','type'=>'number']],
                [['key'=>'litres','type'=>'number','required'=>true,'min'=>0.01],['key'=>'fuel_type','type'=>'select','options'=>['Petrol','Diesel','Lubricant','Other']]],
                ['Identify the vehicle or equipment.','Record litres and retain the receipt.']],
            ['communication_airtime','Communication & Airtime','Airtime, data or other communication cost.','mdi-phone-outline','per_item',false,
                [['key'=>'coverage_period','type'=>'text','placeholder'=>'e.g. August 2026']],
                [['key'=>'phone_number','type'=>'phone','required'=>true],['key'=>'network','type'=>'select','options'=>['Safaricom','Airtel','Telkom','Other']]],
                ['Enter the benefiting phone number on each line.']],
            ['miscellaneous','Miscellaneous','An exceptional request that fits no configured type.','mdi-tag-outline','single',false,
                [['key'=>'exception_reason','type'=>'textarea','required'=>true,'help'=>'Explain why no standard requisition type applies.']],
                [],['Use only when no standard type fits. Finance may return unclear requests.']],
        ];
        foreach ($types as $index => [$code,$name,$description,$icon,$mode,$project,$requestFields,$itemFields,$instructions]) {
            DB::table('petty_cash_requisition_types')->insert([
                'code'=>$code,'name'=>$name,'description'=>$description,'icon'=>$icon,'recipient_mode'=>$mode,
                'requires_project'=>$project,'request_fields'=>json_encode($requestFields),'item_fields'=>json_encode($itemFields),
                'instructions'=>json_encode($instructions),'is_active'=>true,'sort_order'=>($index+1)*10,'created_at'=>$now,'updated_at'=>$now,
            ]);
        }

        DB::table('petty_cash_requisitions')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $type = DB::table('petty_cash_requisition_types')->where('name', $row->category)->first();
                if ($type) DB::table('petty_cash_requisitions')->where('id', $row->id)->update(['requisition_type_id'=>$type->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_requisition_items', fn (Blueprint $table) => $table->dropColumn('details'));
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requisition_type_id');
            $table->dropColumn(['custom_fields','type_snapshot']);
        });
        Schema::dropIfExists('petty_cash_requisition_types');
    }
};
