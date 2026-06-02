<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WDevice;
use Illuminate\Support\Facades\DB;

class GenerateMonthlyPayouts extends Command
{
    protected $signature = 'payouts:generate';
    protected $description = 'Generate month-wise payouts';

    public function handle()
    {
        DB::beginTransaction();

        try {

            // MCP (Company)
            $this->processRole('company_id', 'company_payout', 2);

            // CP (Agent)
            $this->processRole('agent_id', 'other_payout', 4);

            // PROMOTER
            $this->processRole('promoter_id', 'employee_payout', 6);

            DB::commit();

            $this->info('✅ Month-wise payouts generated');

        } catch (\Throwable $e) {

            DB::rollBack();

            \Log::error('Payout failed: ' . $e->getMessage());

            $this->error($e->getMessage());
        }
    }

    private function processRole($entityColumn, $payoutColumn, $role)
    {
        $rows = WDevice::selectRaw("
                $entityColumn as entity_id,
                YEAR(created_at) as year,
                MONTH(created_at) as month,
                MIN(DATE(created_at)) as start_date,
                MAX(DATE(created_at)) as end_date,
                COUNT(*) as total_devices,
                SUM(product_price) as total_amount,
                SUM($payoutColumn) as payout_amount
            ")
            ->whereNotNull($entityColumn)
            ->groupBy($entityColumn, 'year', 'month')
            ->having('payout_amount', '>', 0)
            ->get();

        foreach ($rows as $row) {

            $month = str_pad($row->month, 2, '0', STR_PAD_LEFT);

            DB::table('payouts')->updateOrInsert(
                [
                    'entity_id'  => $row->entity_id,
                    'role'       => $role,
                    'start_date' => $row->start_date
                ],
                [
                    'payout_code'   => $this->getRoleCode($role) . '-' . $row->year . $month . '-' . $row->entity_id,
                    'total_devices' => $row->total_devices,
                    'total_amount'  => $row->total_amount,
                    'payout_amount' => $row->payout_amount,
                    'end_date'      => $row->end_date,
                    'status'        => 'pending',
                    'updated_at'    => now(),
                    'created_at'    => now()
                ]
            );
        }
    }

    private function getRoleCode($role)
    {
        return match ((string)$role) {
            '2' => 'MCP',
            '4' => 'CP',
            '6' => 'PRO',
            default => 'GEN'
        };
    }
}