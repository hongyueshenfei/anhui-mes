<?php

namespace App\Modules\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 手工建单请求校验
 *
 * - 支持多产品明细（lines）
 * - 产品按编码（金蝶物料编码）匹配，不存在即校验失败
 */
class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:128'],
            'delivery_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer', 'between:0,5'],
            'remark' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_code' => ['required', 'string', 'exists:products,code'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:10000000'],
            'lines.*.delivery_date' => ['nullable', 'date'],
            'lines.*.priority' => ['nullable', 'integer', 'between:0,5'],
            'lines.*.mold_id' => ['nullable', 'integer', 'exists:molds,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.*.product_code.exists' => '产品编码不存在（未在产品档案中维护）',
            'lines.*.qty.min' => '明细数量必须大于 0',
            'lines.required' => '订单明细不能为空',
        ];
    }
}
