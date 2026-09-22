<?php

namespace App\Modules\Plm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 产品创建/更新请求
 */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('id');

        return [
            'code' => [
                // 编辑场景不强制携带（编码与金蝶对齐，创建后不建议修改）
                $productId ? 'sometimes' : 'required',
                'string', 'max:64',
                'unique:products,code'.($productId ? ','.$productId : ''),
            ],
            'name' => ['required', 'string', 'max:128'],
            'spec' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:16'],
            'status' => ['sometimes', 'in:developing,trial,mass,obsolete'],
            'default_mold_id' => ['nullable', 'integer', 'exists:molds,id'],
            'default_route_id' => ['nullable', 'integer', 'exists:routes,id'],
            'weight' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => '请输入产品编码（与金蝶物料编码一致）',
            'code.unique' => '产品编码已存在',
            'name.required' => '请输入产品名称',
            'status.in' => '产品状态不合法',
        ];
    }
}