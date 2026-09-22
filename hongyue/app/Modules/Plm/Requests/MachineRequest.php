<?php

namespace App\Modules\Plm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 机台创建/更新请求
 *
 * 客户确认「资源强对应」：machines.type 扩展支持 paint_line/effect_line/pack_line（产线），
 * 机台/产线必须归属车间（workshop_id），作为排产/报工的资源归属依据。
 */
class MachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $machineId = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:32', 'unique:machines,code'.($machineId ? ','.$machineId : '')],
            'name' => ['required', 'string', 'max:64'],
            'type' => ['required', 'in:injection,blow,paint_line,effect_line,pack_line'],
            'workshop_id' => ['required', 'integer', 'exists:workshops,id'],
            'capacity_per_hour' => ['required', 'numeric', 'min:0'],
            'mold_change_min' => ['required', 'integer', 'min:0'],
            'shift_mode' => ['required', 'in:two_shift,day'],
            'status' => ['required', 'in:running,stopped,maintenance'],
            'is_active' => ['sometimes', 'in:0,1'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => '请输入机台编码',
            'code.unique' => '机台编码已存在',
            'type.in' => '机台类型仅支持 injection/blow/paint_line/effect_line/pack_line',
            'workshop_id.required' => '机台/产线必须归属车间（workshop_id）',
            'workshop_id.exists' => '车间不存在',
            'shift_mode.in' => '班制仅支持 two_shift/day',
        ];
    }
}
