<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return $router->app->version();
});


$router->group(['prefix' => 'api'], function () use ($router) {

//global after middleware -- MeasureProcessingTime is added -- to measure processing time of each requeste
  $router->get('jobs',  ['uses' => 'JobController@dequeue']);  // to get current non-completed job with the highest priority
  $router->get('jobs/{id}', ['uses' => 'JobController@checkStatus']);  // to check the status of a job using the jobid

  $router->post('jobs', ['uses' => 'JobController@enqueue']);  // to post job to queue
  $router->post('jobs/multiple', ['uses' => 'JobController@enqueueMultiple']); //to post more than one job to the queue

  $router->put('jobs/{id}', ['uses' => 'JobController@update']); //to update the job if its processed by the Job Processor
});
