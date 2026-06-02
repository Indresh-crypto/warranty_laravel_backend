<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;
use App\Models\CompanyEmployee;
use App\Mail\InactiveRetailerMail;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class SendInactiveRetailerReport extends Command
{
    protected $signature = 'report:inactive-retailers';

    protected $description =
        'Send inactive retailer report to MCP, Admin and Employees';

    public function handle()
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | LAST ACTIVE DATE
            |--------------------------------------------------------------------------
            */

            $date = Carbon::now()->subDays(2);

            /*
            |--------------------------------------------------------------------------
            | FETCH INACTIVE RETAILERS
            |--------------------------------------------------------------------------
            */

            $retailers = Company::query()

                ->where('role', 5)

                ->where('status', 1)

                ->whereDoesntHave('devices', function ($q) use ($date) {

                    $q->where(
                        'created_at',
                        '>=',
                        $date
                    );
                })

                ->select([
                    'id',
                    'business_name',
                    'company_code',
                    'contact_person',
                    'contact_phone',
                    'contact_email',
                    'state',
                    'district',
                    'city',
                    'pincode',
                    'company_id',
                    'agent_id',
                    'created_at'
                ])

                ->get();

            /*
            |--------------------------------------------------------------------------
            | NO DATA
            |--------------------------------------------------------------------------
            */

            if ($retailers->isEmpty()) {

                $this->info(
                    'No inactive retailers found.'
                );

                return 0;
            }

            /*
            |--------------------------------------------------------------------------
            | GROUP BY MCP COMPANY ID
            |--------------------------------------------------------------------------
            */

            $groupedRetailers =
                $retailers->groupBy('company_id');

            foreach (
                $groupedRetailers as
                $companyId => $companyRetailers
            ) {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | EMAIL COLLECTION
                    |--------------------------------------------------------------------------
                    */

                    $emails = [];

                    /*
                    |--------------------------------------------------------------------------
                    | ADMIN + MCP EMAILS
                    |--------------------------------------------------------------------------
                    */

                    $companyEmails = Company::query()

                        ->where(function ($q) use ($companyId) {

                            $q->where('id', $companyId)

                              ->orWhere('company_id', $companyId);
                        })

                        ->whereIn('role', [1, 2])

                        ->whereNotNull('contact_email')

                        ->pluck('contact_email')

                        ->toArray();

                    $emails = array_merge(
                        $emails,
                        $companyEmails
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | CP EMAILS (AGENT)
                    |--------------------------------------------------------------------------
                    */

                    $agentIds = $companyRetailers

                        ->pluck('agent_id')

                        ->filter()

                        ->unique()

                        ->toArray();

                    if (!empty($agentIds)) {

                        $cpEmails = Company::query()

                            ->whereIn('id', $agentIds)

                            ->where('role', 4)

                            ->whereNotNull('contact_email')

                            ->pluck('contact_email')

                            ->toArray();

                        $emails = array_merge(
                            $emails,
                            $cpEmails
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | LOCATION FILTERS
                    |--------------------------------------------------------------------------
                    */

                    $states = $companyRetailers

                        ->pluck('state')

                        ->filter()

                        ->unique()

                        ->toArray();

                    $districts = $companyRetailers

                        ->pluck('district')

                        ->filter()

                        ->unique()

                        ->toArray();

                    $pincodes = $companyRetailers

                        ->pluck('pincode')

                        ->filter()

                        ->unique()

                        ->toArray();

                    /*
                    |--------------------------------------------------------------------------
                    | EMPLOYEE EMAILS
                    |--------------------------------------------------------------------------
                    */

                    $employeeQuery =
                        CompanyEmployee::query()

                            ->where('company_id', $companyId)

                            ->whereNotNull('official_email');

                    /*
                    |--------------------------------------------------------------------------
                    | MATCH BY STATE / DISTRICT / PINCODE
                    |--------------------------------------------------------------------------
                    */

                    $employeeQuery->where(function ($query) use (
                        $states,
                        $districts,
                        $pincodes
                    ) {

                        /*
                        |--------------------------------------------------------------------------
                        | STATE MATCH
                        |--------------------------------------------------------------------------
                        */

                        foreach ($states as $state) {

                            $query->orWhereRaw(
                                "FIND_IN_SET(?, state)",
                                [$state]
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | DISTRICT MATCH
                        |--------------------------------------------------------------------------
                        */

                        foreach ($districts as $district) {

                            $query->orWhereRaw(
                                "FIND_IN_SET(?, district)",
                                [$district]
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | PINCODE MATCH
                        |--------------------------------------------------------------------------
                        */

                        foreach ($pincodes as $pincode) {

                            $query->orWhereRaw(
                                "FIND_IN_SET(?, pincode)",
                                [$pincode]
                            );
                        }
                    });

                    $employeeEmails =
                        $employeeQuery

                            ->pluck('official_email')

                            ->toArray();

                    $emails = array_merge(
                        $emails,
                        $employeeEmails
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | FIXED EMAIL
                    |--------------------------------------------------------------------------
                    */

                    $emails[] =
                        'indresh@goelectronix.com';

                    /*
                    |--------------------------------------------------------------------------
                    | CLEAN EMAILS
                    |--------------------------------------------------------------------------
                    */

                    $emails = array_unique(

                        array_filter($emails)
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | NO EMAIL FOUND
                    |--------------------------------------------------------------------------
                    */

                    if (empty($emails)) {

                        \Log::warning(

                            'NO EMAIL FOUND FOR INACTIVE REPORT',

                            [
                                'company_id' =>
                                    $companyId
                            ]
                        );

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | SEND MAIL
                    |--------------------------------------------------------------------------
                    */

                    Mail::to($emails)->queue(

                        new InactiveRetailerMail(

                            $companyRetailers,

                            2
                        )
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | LOG SUCCESS
                    |--------------------------------------------------------------------------
                    */

                    \Log::info(

                        'INACTIVE RETAILER REPORT SENT',

                        [

                            'company_id' =>
                                $companyId,

                            'total_retailers' =>
                                $companyRetailers->count(),

                            'total_emails' =>
                                count($emails),

                            'emails' =>
                                $emails
                        ]
                    );

                    $this->info(

                        'Mail sent successfully for company ID: '

                        . $companyId
                    );

                } catch (\Throwable $e) {

                    \Log::error(

                        'FAILED TO SEND COMPANY REPORT',

                        [

                            'company_id' =>
                                $companyId,

                            'message' =>
                                $e->getMessage(),

                            'line' =>
                                $e->getLine()
                        ]
                    );
                }
            }

            $this->info(
                'Inactive retailer report completed.'
            );

            return 0;

        } catch (\Throwable $e) {

            \Log::error(

                'INACTIVE RETAILER REPORT FAILED',

                [

                    'message' =>
                        $e->getMessage(),

                    'line' =>
                        $e->getLine(),

                    'file' =>
                        $e->getFile()
                ]
            );

            $this->error(
                $e->getMessage()
            );

            return 1;
        }
    }
}