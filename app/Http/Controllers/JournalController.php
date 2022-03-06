<?php

namespace App\Http\Controllers;

use App\Exports\JournalsExport;
use App\Imports\JournalsImport;
use App\Mail\JournalChange;
use App\Models\AdjustingHistory;
use App\Models\ChartAccount;
use App\Models\Journal;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class JournalController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = Journal::with(['user', 'chartAccount', 'accountingPeriod', 'project', 'asset', 'bankAccount']);

        if($request->keyword){
            $query->where('title','ILIKE','%'.$request->keyword.'%');
        }

        if($request->category > 2){
            $query->where('status','>=', $request->category);
        }

        if($request->category <= 2 && $request->category > 0){
            $query->where('status','=', $request->category);
        }

        if($request->chart){
            $query->whereHas('chartAccount', function($query) use($request){
                $query->where('chart_account_id', $request->chart);
            });
        }

        if($request->reimburse != null){
            $query = $query->where('is_reimburse','=', $request->reimburse);
        }

        if($request->date){
            $query->whereMonth('date','=', date($request->date));
        }

        $journal = $query->get();

        foreach($journal as $value){
            $value->project_id = Project::findOrFail($value->project_id)->name;
            $value->user_id = User::findOrFail($value->user_id)->name;
            $value->chart_account_id = ChartAccount::findOrFail($value->chart_account_id)->name;
        }

        foreach ($journal as $key => $value) {
            $journal[$key]->token = Crypt::encryptString($journal[$key]->id);
        }

        $response =[
            'message' => 'List Journal',
            'data' => $journal
        ];
        return response()->json($response, Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // return response()->json($request, Response::HTTP_CREATED);

        $input = $request->all();

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'max:45'],
            'date' => ['required'],
            'remark' => ['max:1000'],
            'ref' => ['max:45'],
            'filebukti' => ['required', 'mimes:png,jpg,jpeg,doc,docx,pdf,txt,csv', 'max:2048'],
            'balance' => ['required'],
            'is_reimburse' => ['required'],
            'chart_account_id' => ['required'],
            'accounting_period_id' => ['required'],
            'bank_account_id' => ['required'],
            'project_id' => ['required'],
            'user_id' => ['required']
        ]);

        if($validator->fails()){
            return response()->json($validator->errors(),
            Response::HTTP_UNPROCESSABLE_ENTITY);
        }



            if($file = $request->file('filebukti')){
                $path = $file->store('public/files');
                $name = $file->getClientOriginalName();
                $file->move($path, $name);
                $input['filebukti'] = "$name";
            }

            $journal = Journal::create($input);
            AdjustingHistory::create($input);

            // balance in ca
            $idchartacc = $input['chart_account_id'];
            $ca = ChartAccount::findOrFail($idchartacc);
            $ca->balance = $ca->balance + $input['balance'];
            $ca->save();

            $response = [
                'message' => 'A new journal row created',
                'data' => $journal
            ];

            // return response()->json($response, Response::HTTP_CREATED);

    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($token)
    {
        $id = Crypt::decryptString($token);
        $journal = Journal::findOrFail($id);
        $response = [
            'message' => 'A journal row shown',
            'data' => $journal
        ];
        return response()->json($response, Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */

    public function update(Request $request, $token)
    {
        $id = Crypt::decryptString($token);
        $journal = Journal::findOrFail($id);

        $validator = Validator::make($request->all(), [
           'title' => ['required', 'max:45'],
            'date' => ['required'],
            'remark' => ['max:1000'],
            'ref' => ['max:45'],
            'is_reimburse' => ['required'],
            'filebukti' => ['mimes:png,jpg,jpeg,doc,docx,pdf,txt,csv', 'max:2048'],
            'chart_account_id' => ['required'],
            'accounting_period_id' => ['required'],
            'bank_account_id' => ['required'],
            'project_id' => ['required'],
            'user_id' => ['required']
        ]);

        if($validator->fails()){
            return response()->json($validator->errors(),
            Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $input = $request->all();

        try {
            if($file = $request->file('filebukti')){
                $path = $file->store('public/files');
                $name = $file->getClientOriginalName();
                $file->move($path, $name);
                $input['filebukti'] = "$name";
            }
        else{
            $input['filebukti'] = "$journal->filebukti";
        }

        if($input['balance'] > $journal->balance){
            $res = $input['balance'] - $journal->balance;
            $idchartacc = $input['chart_account_id'];
            $ca = ChartAccount::findOrFail($idchartacc);
            $ca->balance = $ca->balance + $res;
            $ca->save();
        }

        elseif ($input['balance'] < $journal->balance) {
            $res = $journal->balance - $input['balance'] ;
            $idchartacc = $input['chart_account_id'];
            $ca = ChartAccount::findOrFail($idchartacc);
            $ca->balance = $ca->balance - $res;
            $ca->save();
        }

        $journal->update($input);
        $this->sendEmail($id);
        AdjustingHistory::create($input);

            $response = [
                'message' => 'A journal row updated',
                'data' => $journal
            ];

            return response()->json($response, Response::HTTP_OK);

        } catch (QueryException $e) {
            return response()->json([
                'message' => "Failed " . $e->errorInfo
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($token)
    {
        $id = Crypt::decryptString($token);
        $journal = Journal::findOrFail($id);

        try {
            $journal->delete();
            $response = [
                'message' => 'A journal row deleted',
                'data' => $journal
            ];

            return response()->json($response, Response::HTTP_OK);

        } catch (QueryException $e) {
            return response()->json([
                'message' => "Failed " . $e->errorInfo
            ]);
        }
    }

    public function draftToProcess($token){
        $id = Crypt::decryptString($token);
        $journal = Journal::findOrFail($id);

        $journal->status = 2;
        $journal->save();

        $this->sendEmail($id);
        $response = [
            'message' => 'A journal has been moved into process phase',
            'data' => $journal
        ];

        return response()->json($response, Response::HTTP_OK);
    }

    public function validationStatus(Request $request, $token){
        $id = Crypt::decryptString($token);
        $journal = Journal::findOrFail($id);

        $journal->status = 3;
        if($journal->is_reimburse = 1){
            $input = $request->all();
            if($file = $request->file('buktireimburse')){
                $path = $file->store('public/files');
                $name = $file->getClientOriginalName();
                $file->move($path, $name);
                $input['buktireimburse'] = "$name";
            }
            $journal->buktireimburse = $input;
        }
        $journal->save();
        $this->sendEmail($id);

        $response = [
            'message' => 'A journal has been verified',
            'data' => $journal
        ];

        return response()->json($response, Response::HTTP_OK);
    }

    public function declineStatus($token){
        $id = Crypt::decryptString($token);
        $journal = Journal::findOrFail($id);

        $journal->status = 4;
        $journal->save();
        $this->sendEmail($id);

        $response = [
            'message' => 'A journal has been declined',
            'data' => $journal
        ];

        return response()->json($response, Response::HTTP_OK);
    }

    public function export(){
        $expexc = Excel::download(new JournalsExport, 'journals.xlsx');
        // dd($expexc);
        $response = [
            'message' => 'The file has been downloaded',
            'data' => $expexc
        ];

        return response()->json($response, Response::HTTP_OK);
    }

    public function import(){
        Excel::import(new JournalsImport, 'journals.xlsx');

        $response = [
            'message' => 'The file has been uploaded'
        ];

        return response()->json($response, Response::HTTP_OK);
    }
    public function sendEmail($id){
        $jurnal = Journal::findOrFail($id);
        $iduser = $jurnal->user_id;
        $user = User::findOrFail($iduser);
        $email = $user->email;

        $details=[
            'title' => 'Announcement about journal update',
            'body' => 'Your journal has been updated.'
        ];

        Mail::to($email)->send(new JournalChange($details));
    }

}
