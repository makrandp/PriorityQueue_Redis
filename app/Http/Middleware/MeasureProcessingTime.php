<?php

namespace App\Http\Middleware;

use Closure;

class MeasureProcessingTime
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
      $response = $next($request);

      $response->headers->set('X-Processing-Time', microtime(true) - LUMEN_START);

     return $response;
    }
}
