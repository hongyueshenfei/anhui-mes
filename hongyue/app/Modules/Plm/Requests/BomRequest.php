<?php

namespace App\Modules\Plm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BOM 版本创建 / 明细更新请求
 */
class BomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('put')) {
            // 更新场景：仅明细（草稿版本可编辑）
            return [
                'lines' => ['required', 'array', 'min:1'],
                'lines.*.seq' => ['sometimes', 'integer', 'min:1'],
                'lines.*.item_code' => ['required', 'string', 'max:64'],
                'lines.*.item_name' => ['required', 'string', 'max:128'],
                'lines.*.qty' => ['sometimes', 'numeric', 'gt:0'],
                'lines.*.unit' => ['sometimes', 'string', 'max:16'],
                'lines.*.loss_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
                'lines.*.is_key' => ['sometimes', 'in:0,1'],
            ];
        }

        // 创建场景：版本必填；明细允许为空数组（发布动作由服务层强校验）
        return [
            'version' => ['required', 'string', 'max:16'],
            'remark' => ['nullable', 'string', 'max:255'],
            'lines' => ['nullable', 'array'],
            'lines.*.seq' => ['sometimes', 'integer', 'min:1'],
            'lines.*.item_code' => ['required_with:lines', 'string', 'max:64'],
            'lines.*.item_name' => ['required_with:lines', 'string', 'max:128'],
            'lines.*.qty' => ['sometimes', 'numeric', 'gt:0'],
            'lines.*.unit' => ['sometimes', 'string', 'max:16'],
            'lines.*.loss_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'lines.*.is_key' => ['sometimes', 'in:0,1'],
        ];
    }

    public function messages(): array
    {
        return [
            'version.required' => '请输入 BOM 版本号',
            'lines.*.item_code.required' => 'BOM 明细缺少材料编码',
            'lines.*.item_name.required' => 'BOM 明细缺少材料名称',
        ];
    }
}