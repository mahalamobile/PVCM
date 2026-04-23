<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignDataRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data' => ['required', 'array', 'min:1'],
            'data.*.user_id' => ['required', 'string', 'max:255'],
            'data.*.video_url' => ['required', 'url', 'max:2048'],
            'data.*.custom_fields' => ['nullable', 'array'],
        ];
    }

    /**
     * Prepare payload so an incoming raw array also works.
     */
    protected function prepareForValidation(): void
    {
        if ($this->isAssocArray()) {
            return;
        }

        $all = $this->all();

        if (is_array($all) && array_is_list($all)) {
            $this->merge(['data' => $all]);
        }
    }

    private function isAssocArray(): bool
    {
        $all = $this->all();

        return is_array($all) && ! array_is_list($all);
    }
}
