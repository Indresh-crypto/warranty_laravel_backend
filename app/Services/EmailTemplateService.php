<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class EmailTemplateService
{
    public function parseTemplate($template)
    {
        $body = $template->body;
        $subject = $template->subject;

        foreach ($template->mappings as $map) {

            $value = DB::table($map->table_name)
                ->value($map->column_name);

            $placeholder = '{{' . $map->placeholder . '}}';

            $body = str_replace($placeholder, $value ?? '', $body);
            $subject = str_replace($placeholder, $value ?? '', $subject);
        }

        return [
            'subject' => $subject,
            'body' => $body
        ];
    }
}