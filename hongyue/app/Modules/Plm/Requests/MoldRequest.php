<?php

namespace App\Modules\Plm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 模具创建/更新请求
 */
class MoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $moldId = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:32', 'unique:molds,code'.($moldId ? ','.$moldId : '')],
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', 'in:injection,blow'],
            'status' => ['sometimes', 'in:in_use,idle,repair,scrapped'],
            'location' => ['nullable', 'string', 'max:64'],
            'cavity_count' => ['required', 'integer', 'min:1'],
            'lifetime_openings' => ['required', 'integer', 'min:0'],
            'opened_count' => ['sometimes', 'integer', 'min:0'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'purchase_date' => ['nullable', 'date'],
            'vendor' => ['nullable', 'string', 'max:128'],
            'maintain_cycle_days' => ['nullable', 'integer', 'min:1'],
            'last_maintain_at' => ['nullable', 'date'],
            'next_maintain_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'default_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'machine_ids' => ['nullable', 'array'],
            'machine_ids.*' => ['integer', 'exists:machines,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => '请输入模具编码',
            'code.unique' => '模具编码已存在',
            'cavity_count.min' => '模穴数必须大于 0',
            'lifetime_openings.min' => '设计寿命不能为负',
        ];
    }
}