<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use App\Models\Job;
use DB;

class JobController extends Controller
{
  public function enqueue(Request $request)
  {
  //  command,priority,submitter_id
      $parameters = $request->all();
      $rules = [
          'submitter_id'     => 'required',
          'command'          => 'required',
          'priority'         => 'numeric'     //priority is optinal || default is 1
      ];

      $validator = Validator::make($parameters, $rules);
      if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()]);
      } else {
            $command = $request->input('command');
            $submitter_id = $request->input('submitter_id');
            //if priority not posted --> then default 1
            $priority = $request->has('priority') ? $request->input('priority') : 1 ;

            $job = new Job;
            $job->command = $command;
            $job->submitter_id = $submitter_id;
            $job->save();
            $jobid = $job->id;
            //atomic set operation in cache
            Redis::MULTI();
            Redis::zadd('priorityq',$priority,$jobid);
            Redis::set("priorityq:{$jobid}:command",$command); // command related to the job
            Redis::set("priorityq:{$jobid}:status","false");  // status -- job processed or not
            Redis::set("priorityq:{$jobid}:inqueue","true");  //to check whether a job is still in the queue
            Redis::EXEC();

            return response()->json(
                 [
                   'jobid' => "$jobid",
                   'response'=> "job successfully added to the queue"
                 ],201);
      }

  }

  public function enqueueMultiple(Request $request)
  {
    // multiple jobs comes as an array of objects through request body
    // each object has submitter_id,command and priority(optional)
    $jobs = $request->json()->all();
    $priority =1 ;
    //job ids are returned as an array
    $ids= array();
    foreach($jobs as $data){
      //loop through multiple jobs
      $rules = [
          'submitter_id'     => 'required',
          'command'          => 'required',
          'priority'         => 'numeric'
      ];
      $validator = Validator::make($data, $rules);
      if ($validator->fails()) {
        //dont add to database if the validation fails
          continue;
      } else {
        $command = $data['command'];
        $submitter_id = $data['submitter_id'];
        $priority = array_key_exists('priority', $data) ? $data['priority'] : 1;

        $job = new Job();
        $job->submitter_id = $submitter_id;
        $job->command = $command;
        $job->save();
        $jobid = $job->id;
        //atomic operation
        Redis::MULTI();
        Redis::zadd('priorityq',$priority,$jobid);
        Redis::set("priorityq:{$jobid}:command",$command);
        Redis::set("priorityq:{$jobid}:status","false");
        Redis::set("priorityq:{$jobid}:inqueue","true");
        Redis::EXEC();
        array_push($ids,$jobid);
      }
    }

    return response()->json(
         [
           'ids' => $ids
         ]);

  }


  public function dequeue()
  {
    //once a job is requested -- it is removed from the queue -- but database still has its info
    $sucess = false;
    try {
      //always the job with the highest priority is removed from the queue
      $jobid = Redis::zrevrange('priorityq',0,0);
      Redis::MULTI();
      Redis::zrem('priorityq',$jobid[0]);
      Redis::set("priorityq:{$jobid[0]}:inqueue","false");
      Redis::EXEC();
      $sucess = true;
    }catch (Exception $e) {
      $sucess = false;
    }
    if($sucess){
      $command = Redis::get("priorityq:{$jobid[0]}:command");
      //jobid and command are sent as a response
      return response()->json(
          [
            'jobid' => $jobid,
            'command'=> $command
          ]);
    }else{
      return response()->json(
          [
            'response'=> "failure"
          ]);
    }
  }

  public function checkStatus($jobid)
  {
    // status = true ---- job processed successfully
    // status = false ---- job pending
        $status = Redis::get("priorityq:{$jobid}:status");
        error_log($status);
        if($status == "true"){
        return response()->json(
            [
              'status'=> "job processed successfully"
            ]);
        }else {
          return response()->json(
              [
                'status'=> "job pending"
              ]);
        }
  }

  public function update(Request $request, $jobid)
  {
    //a job is marked as processed only via a update method -- only the job which is not in the queue can be marked processed
    $parameters = $request->all();
    $rules = [
        'processor_id'     => 'required'
    ];
    $validator = Validator::make($parameters, $rules);
    if ($validator->fails()) {
          return response()->json(['error' => $validator->errors()]);
    } else {
      $processor_id = $request->input('processor_id');
      $sucess = false;
      $job = Job::find($jobid);
      if (empty($job)) {
          return response()->json(['error' => 'Job not found']);
      } else {
        //if the job is out of the queue then only job processor can process it...
        $inqueue = Redis::get("priorityq:{$jobid}:inqueue");
        error_log($inqueue);
        // inqueue = true --> job still in the queue
        if($inqueue == "true"){
          return response()->json(
              [
                'response'=> "cant updatee - job still inside the queue"
              ]);
        }else{
        $job->processor_id = $processor_id;
        DB::beginTransaction();
        try {
          $job->save();
          DB::commit();
          $sucess = true;
        } catch (\Exception $e) {
          DB::rollback();
          $sucess = false;
        }
        if($sucess){
          //change the status of job once its successfully processed
          Redis::set("priorityq:{$jobid}:status","true");
          return response()->json(
              [
                'response'=> "job processed successfully"
              ]);
        }else{
          return response()->json(
              [
                'response'=> "failure"
              ]);
        }
      }
     }
    }
  }

}
