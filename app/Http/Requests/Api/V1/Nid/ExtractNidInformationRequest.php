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
        $allowedImageExtensions = 'jpg,jpeg,jepg,png,webp,heic,heif';
        $maxUploadSize = 'max:'.config('nid.upload.max_size_kb');

        return [
            'front_image' => ['required', 'file', "mimes:{$allowedImageExtensions}", $maxUploadSize],
            'back_image' => ['required', 'file', "mimes:{$allowedImageExtensions}", $maxUploadSize],
            'ocr_languages' => ['nullable', 'string', 'max:32'],
        ];
    }
}
