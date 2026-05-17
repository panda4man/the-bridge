<?php

namespace App\Http\Requests;

use App\Models\App;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $base = rtrim(config('bridge.repos_path'), '/');
        $this->merge([
            'full_path' => $base . '/' . ltrim($this->input('path', ''), '/'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name'       => 'required|string|max:255',
            'repo_url'   => 'required|string|max:500',
            'branch'     => 'required|string|max:255',
            'path'       => ['required', 'string', 'max:255', 'not_regex:/\.\./'],
            'full_path'  => 'required|string',
            'skip_clone' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('path')) {
                return;
            }
            $full      = $this->input('full_path');
            $skipClone = (bool) $this->input('skip_clone');

            if (App::where('path', $full)->exists()) {
                $validator->errors()->add('path', 'An app already uses this path.');
                return;
            }

            if ($skipClone) {
                if (!is_dir($full)) {
                    $validator->errors()->add('path', 'Directory does not exist at this path.');
                } elseif (!is_dir($full . '/.git')) {
                    $validator->errors()->add('path', 'Directory exists but is not a git repository.');
                }
            } else {
                if (is_dir($full)) {
                    $validator->errors()->add('path', 'Directory already exists on disk.');
                }
            }
        });
    }

    public function appData(): array
    {
        $data               = $this->safe()->only(['name', 'repo_url', 'branch']);
        $data['path']       = $this->input('full_path');
        $data['skip_clone'] = (bool) $this->input('skip_clone');
        return $data;
    }
}
