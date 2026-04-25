<?php

namespace App\Http\Requests\Api\V1\Nid;

use Illuminate\Foundation\Http\FormRequest;

final class ExtractNidInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'front_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('nid.upload.max_size_kb')],
            'back_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('nid.upload.max_size_kb')],
            'ocr_languages' => ['nullable', 'string', 'max:32'],
        ];
    }
}
