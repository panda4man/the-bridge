<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'repo_url' => 'required|string|max:500',
            'branch'   => 'required|string|max:255',
            'path'     => 'required|string|max:500',
        ];
    }
}
