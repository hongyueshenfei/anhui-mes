<?php

namespace App\Modules\Plm\Requests;

use App\Models\OpType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 工艺路线创建/更新请求
 */
class RouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // op_type 动态校验：取字典表启用中的工序类型键（自定义工序类型无需改代码）
        $opTypes = OpType::where('is_active', 1)->pluck('op_type')->all();
        // 更新场景（PUT /routes/{id}）：名称/版本/工序均可缺省（允许只改名称、版本或说明）
        $isUpdate = (bool) $this->route('id');

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:128'],
            'version' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:16'],
            'remark' => ['nullable', 'string', 'max:255'],
            'ops' => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:1'],
            'ops.*.seq' => ['sometimes', 'integer', 'min:1'],
            'ops.*.op_type' => ['required', Rule::in($opTypes)],
            'ops.*.op_name' => ['required', 'string', 'max:64'],
            'ops.*.workshop_id' => ['nullable', 'integer', 'exists:workshops,id'],
            'ops.*.mold_change_min' => ['nullable', 'integer', 'min:0'],
            'ops.*.shift_mode' => ['nullable', 'in:two_shift,day'],
            'ops.*.machine_type' => ['nullable', 'in:injection,blow'],
            'ops.*.rate_key' => ['nullable', 'string', 'max:32'],
            'ops.*.default_machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'ops.*.default_line_id' => ['nullable', 'integer', 'exists:machines,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '请输入工艺路线名称',
            'version.required' => '请输入工艺版本号',
            'ops.required' => '请至少录入一道工序',
            'ops.*.op_type.in' => '工序类型不合法（未启用或不存在）',
            'ops.*.op_name.required' => '工序名必填',
        ];
    }
}
