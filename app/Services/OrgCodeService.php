<?php

namespace App\Services;

use App\Models\OrgUsersMaster;

class OrgCodeService
{
    public function getRoleCode($role)
    {
        return match (strtolower($role)) {
            '5' => 'ARP',
            '2' => 'MCP',
            '6' => 'PRO',
            '4' => 'CP',
            default => 'GEN'
        };
    }

   public function getStateCode($state)
   {
    $states = [

        // =========================
        // STATES
        // =========================

        'andhra pradesh'        => 'AP',
        'arunachal pradesh'     => 'AR',
        'assam'                 => 'AS',
        'bihar'                 => 'BR',
        'chhattisgarh'          => 'CG',
        'goa'                   => 'GA',
        'gujarat'               => 'GJ',
        'haryana'               => 'HR',
        'himachal pradesh'      => 'HP',
        'jharkhand'             => 'JH',
        'karnataka'             => 'KA',
        'kerala'                => 'KL',
        'madhya pradesh'        => 'MP',
        'maharashtra'           => 'MH',
        'manipur'               => 'MN',
        'meghalaya'             => 'ML',
        'mizoram'               => 'MZ',
        'nagaland'              => 'NL',
        'odisha'                => 'OD',
        'punjab'                => 'PB',
        'rajasthan'             => 'RJ',
        'sikkim'                => 'SK',
        'tamil nadu'            => 'TN',
        'telangana'             => 'TS',
        'tripura'               => 'TR',
        'uttar pradesh'         => 'UP',
        'uttarakhand'           => 'UK',
        'west bengal'           => 'WB',

        // =========================
        // UNION TERRITORIES
        // =========================

        'andaman and nicobar islands' => 'AN',
        'chandigarh'                  => 'CH',
        'dadra and nagar haveli and daman and diu' => 'DH',
        'delhi'                       => 'DL',
        'jammu and kashmir'           => 'JK',
        'ladakh'                      => 'LA',
        'lakshadweep'                 => 'LD',
        'puducherry'                  => 'PY',

    ];

    $key = strtolower(trim($state));

    return $states[$key] ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $state), 0, 2));
}

    public function generate($role, $state)
    {
        $roleCode = $this->getRoleCode($role);
        $stateCode = $this->getStateCode($state);

        $last = OrgUsersMaster::where('role', $role)
            ->where('state', $state)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        if ($last && preg_match('/(\d+)$/', $last->org_code, $match)) {
            $number = (int)$match[1] + 1;
        } else {
            $number = 1001;
        }

        return "{$roleCode}-{$stateCode}-{$number}";
    }
}