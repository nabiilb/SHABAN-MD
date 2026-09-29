<?php

namespace App\Http\Requests;

class CaseNoteRequest extends ApiRequest
{
    public static function fields(): array
    {
        $m = 'Write a note first.';

        return ['text' => (new Field)->pre(fn ($v) => is_string($v) ? trim($v) : $v)->rules('required', 'string', 'max:1000')->messages(['required' => $m, 'string' => $m, 'max' => 'Keep notes under 1000 characters.'])];
    }
}
