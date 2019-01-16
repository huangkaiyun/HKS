<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Company;
use App\Department;
use App\Objective;
use App\Http\Requests\ObjectiveRequest;
use App\Charts\SampleChart;
use App\User;

class DepartmentController extends Controller
{
    /**
     * 要登入才能用的Controller
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listOKR(Request $request, Department $department)
    {
        $okrs = [];
        # 預設當前進行OKR
        $pages = $department->searchObjectives($request);
        # 如果有做搜尋則跑此判斷
        if ($request->input('st_date', '') || $request->input('fin_date', '')) {
            $builder = $user->objectives();
            # 判斷起始日期搜索是否為空        
            if ($search = $request->input('st_date', '')) {
                $builder->where(function ($query) use ($search) {
                    $query->where('finished_at', '>=', $search);
                });
            }
            # 判斷終點日期搜索是否為空        
            if ($search = $request->input('fin_date', '')) {
                $builder->where(function ($query) use ($search) {
                    $query->where('started_at', '<=', $search);
                });
            }
            # 判斷使用內建排序與否
            if ($order = $request->input('order', '')) { 
                # 判斷value是以 _asc 或者 _desc 结尾來排序
                if (preg_match('/^(.+)_(asc|desc)$/', $order, $m)) {
                    # 判斷是否為指定的接收的參數
                    if (in_array($m[1], ['started_at', 'finished_at', 'updated_at'])) {   
                        # 開始排序              
                        $builder->orderBy($m[1], $m[2]);
                    }
                }
            }
            # 使用分頁(依照單頁O的筆數上限、利用append記錄搜尋資訊)
            $pages = $builder->paginate(5)->appends([
                'st_date' => $request->input('st_date', ''),
                'fin_date' => $request->input('fin_date', ''),
                'order' => $request->input('order', '')
            ]);
        }
        foreach ($pages as $obj) {
            #打包單張OKR
            $okrs[] = [
                "objective" => $obj,
                "keyresults" => $obj->keyresults,
                "actions" => $obj->actions,
                "chart" => $obj->getChart(),
            ];
        }

        $data = [
            'user' => auth()->user(),
            'owner' => $department,
            'okrs' => $okrs,
            'pages' => $pages,
            'st_date' => $request->input('st_date', ''),
            'fin_date' => $request->input('fin_date', ''),
            'order' => $request->input('order', ''),
        ];

        return view('organization.department.okr', $data);
    }

    public function storeObjective(ObjectiveRequest $request, Department $department)
    {
        $department->addObjective($request);
        return redirect()->route('department.okr', $department->id);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function createRoot()
    {
        $company = Company::where('id', auth()->user()->company_id)->first();
        $departments = Department::where('company_id', $company->id)->get();
        $data = [
            'parent' => $company,
            'self' => '',
            'children' => $departments,
        ];

        return view('organization.department.create', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Department $department)
    {
        $data = [
            'parent' => '',
            'self' => $department,
            'children' => $department->children,
        ];

        return view('organization.department.create', $data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $attr['name'] = $request->department_name;
        $attr['description'] = $request->department_description;
        $attr['user_id'] = auth()->user()->id;
        $attr['company_id'] = auth()->user()->company_id;
        if (substr($request->department_parent, 0, 4) == "self" || substr($request->department_parent, 0, 10) === "department") {
            $attr['parent_department_id'] = preg_replace('/[^\d]/', '', $request->department_parent);
        }
        $department = Department::create($attr);

        if ($request->hasFile('department_img_upload')) {
            $file = $request->file('department_img_upload');
            $filename = date('YmdHis') . '.' . $file->getClientOriginalExtension();
            $file->storeAs('public/department/' . $department->id, $filename);
            $department->update(['avatar' => '/storage/department/' . $department->id . '/' . $filename]);
        }

        return redirect()->route('organization');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  Department $department
     * @return \Illuminate\Http\Response
     */
    public function edit(Department $department)
    {
        return view('organization.department.edit', ['department' => $department]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  Department $department
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Department $department)
    {
        $attr['name'] = $request->department_name;
        $attr['description'] = $request->department_description;
        $department->update($attr);

        if ($request->hasFile('department_img_upload')) {
            $file = $request->file('department_img_upload');
            $filename = date('YmdHis') . '.' . $file->getClientOriginalExtension();
            $file->storeAs('public/department/' . $department->id, $filename);
            $department->update(['avatar' => '/storage/department/' . $department->id . '/' . $filename]);
        }

        return redirect()->route('organization');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  Department $department
     * @return \Illuminate\Http\Response
     */
    public function destroy(Department $department)
    {
        $users = User::where(['company_id' => auth()->user()->company_id, 'department_id' => $department->id])->get();
        foreach ($users as $user) {
            $user->update(['department_id' => null]);
        }
        $department->delete();

        return redirect('organization');
    }
}
