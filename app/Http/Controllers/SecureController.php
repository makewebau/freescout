<?php

namespace App\Http\Controllers;

use App\ActivityLog;
use App\Option;
use App\SendLog;
use App\Thread;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SecureController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function dashboard()
    {
        $mailboxes = auth()->user()->mailboxesCanView();

        return view('secure/dashboard', ['mailboxes' => $mailboxes]);
    }

    /**
     * Logs.
     *
     * @return \Illuminate\Http\Response
     */
    public function logs(Request $request)
    {
        function addCol($cols, $col)
        {
            if (!in_array($col, $cols)) {
                $cols[] = $col;
            }

            return $cols;
        }

        // No need to check permissions here, as they are checked in routing

        $names = ActivityLog::select('log_name')->distinct()->pluck('log_name')->toArray();

        $activities = [];
        $cols = [];
        $page_size = 20;
        $name = '';

        if (!empty($request->name)) {
            $activities = ActivityLog::inLog($request->name)->orderBy('created_at', 'desc')->paginate($page_size);
            $name = $request->name;
        } elseif (count($names)) {
            $name =  ActivityLog::NAME_OUT_EMAILS;
            // $activities = ActivityLog::inLog($names[0])->orderBy('created_at', 'desc')->paginate($page_size);
            // $name = $names[0];
        }

        if ($name != ActivityLog::NAME_OUT_EMAILS) {
            $logs = [];
            $cols = ['date'];
            foreach ($activities as $activity) {
                $log = [];
                $log['date'] = $activity->created_at;
                if ($activity->causer) {
                    if ($activity->causer_type == 'App\User') {
                        $cols = addCol($cols, 'user');
                        $log['user'] = $activity->causer;
                    } else {
                        $cols = addCol($cols, 'customer');
                        $log['customer'] = $activity->causer;
                    }
                }
                $log['event'] = $activity->getEventDescription();

                $cols = addCol($cols, 'event');

                foreach ($activity->properties as $property_name => $property_value) {
                    if (!is_string($property_value)) {
                        $property_value = json_encode($property_value);
                    }
                    $log[$property_name] = $property_value;
                    $cols = addCol($cols, $property_name);
                }

                $logs[] = $log;
            }
        } else {
            // Outgoing emails are displayed from send log
            $logs = [];
            $cols = [
                'date',
                'type',
                'email',
                'status',
                'conversation',
                'user',
                'customer',
            ];

            $activities = SendLog::orderBy('created_at', 'desc')->paginate($page_size);

            foreach ($activities as $record) {

                $conversation = '';
                if ($record->thread_id) {
                    $conversation = Thread::find($record->thread_id);
                }
                
                $status = $record->getStatusName();
                if ($record->status_message) {
                    $status .= '. '.$record->status_message;
                }

                $logs[] = [
                    'date'          => $record->created_at,
                    'type'          => $record->getMailTypeName(),
                    'email'         => $record->email,
                    'status'        => $status,
                    'conversation'  => $conversation,
                    'user'          => $record->user,
                    'customer'      => $record->customer,
                ];
            }

        }

        array_unshift($names, ActivityLog::NAME_OUT_EMAILS);

        if (!in_array($name, $names)) {
            $names[] = $name;
        }

        return view('secure/logs', [
            'logs' => $logs, 
            'names' => $names,
            'current_name' => $name,
            'cols' => $cols,
            'activities' => $activities
        ]);
    }

    /**
     * Logs page submitted
     */
    public function logsSubmit(Request $request)
    {
        // No need to check permissions here, as they are checked in routing

        $name = '';
        if (!empty($request->name)) {
            $activities = ActivityLog::inLog($request->name)->orderBy('created_at', 'desc')->get();
            $name = $request->name;
        } elseif (count($names = ActivityLog::select('log_name')->distinct()->get()->pluck('log_name'))) {
            $name =  ActivityLog::NAME_OUT_EMAILS;
            // $activities = ActivityLog::inLog($names[0])->orderBy('created_at', 'desc')->get();
            // $name = $names[0];
        }

        switch ($request->action) {
            case 'clean':
                if ($name && $name != ActivityLog::NAME_OUT_EMAILS) {
                    ActivityLog::where('log_name', $name)->delete();
                    \Session::flash('flash_success_floating', __('Log successfully cleared'));
                }
                break;
        }

        return redirect()->route('logs', ['name' => $name]);
    }

    /**
     * System status.
     */
    public function system(Request $request)
    {
        // PHP extensions
        $php_extensions = [];
        foreach (\Config::get('app.required_extensions') as $extension_name) {
            $alternatives = explode('/', $extension_name);
            if ($alternatives) {
                foreach ($alternatives as $alternative) {
                    $php_extensions[$extension_name] = extension_loaded(trim($alternative));
                    if ($php_extensions[$extension_name]) {
                        break;
                    }
                }
            } else {
                $php_extensions[$extension_name] = extension_loaded($extension_name);
            }
        }

        // Jobs
        $queued_jobs = \App\Job::orderBy('created_at', 'desc')->get();
        $failed_jobs = \App\FailedJob::orderBy('failed_at', 'desc')->get();

        // Commands
        $commands_list = ['freescout:fetch-emails', 'queue:work'];
        foreach ($commands_list as $command_name) {
            $status_texts = [];

            // Check if command is running now
            if (function_exists('shell_exec')) {
                $running_commands = 0;

                try {
                    $processes = preg_split("/[\r\n]/", shell_exec("ps aux | grep '{$command_name}'"));
                    $pids = [];
                    foreach ($processes as $process) {
                        preg_match("/^[\S]+\s+([\d]+)\s+/", $process, $m);
                        if (!preg_match("/(sh \-c|grep )/", $process) && !empty($m[1])) {
                            $running_commands++;
                            $pids[] = $m[1];
                        }
                    }
                } catch (\Exception $e) {
                    // Do nothing
                }
                if ($running_commands == 1) {
                    $commands[] = [
                        'name'        => $command_name,
                        'status'      => 'success',
                        'status_text' => __('Running'),
                    ];
                    continue;
                } elseif ($running_commands > 1) {
                    // queue:work command is stopped by settings a cache key
                    \Cache::forever('illuminate:queue:restart', Carbon::now()->getTimestamp());
                    $commands[] = [
                        'name'        => $command_name,
                        'status'      => 'error',
                        'status_text' => __(':number commands were running at the same time. Commands have been restarted', ['number' => $running_commands]),
                    ];

                    //unset($pids[0]);
                    // $commands[] = [
                    //     'name'        => $command_name,
                    //     'status'      => 'error',
                    //     'status_text' => __(':number commands are running at the same time. Please stop extra commands by executing the following console command:', ['number' => $running_commands]).' kill '.implode(' | kill ', $pids),
                    // ];
                    continue;
                }
            }
            // Check last run
            $option_name = str_replace('freescout_', '', preg_replace('/[^a-zA-Z0-9]/', '_', $command_name));

            $date_text = '?';
            $last_run = Option::get($option_name.'_last_run');
            if ($last_run) {
                $date = Carbon::createFromTimestamp($last_run);
                $date_text = User::dateFormat($date);
            }
            $status_texts[] = __('Last run:').' '.$date_text;

            $date_text = '?';
            $last_successful_run = Option::get($option_name.'_last_successful_run');
            if ($last_successful_run) {
                $date_ = Carbon::createFromTimestamp($last_successful_run);
                $date_text = User::dateFormat($date);
            }
            $status_texts[] = __('Last successful run:').' '.$date_text;

            $status = 'error';
            if ($last_successful_run && $last_run && (int) $last_successful_run >= (int) $last_run) {
                unset($status_texts[0]);
                $status = 'success';
            }

            $commands[] = [
                'name'        => $command_name,
                'status'      => $status,
                'status_text' => implode(' / ', $status_texts),
            ];
        }

        return view('secure/system', [
            'commands'       => $commands,
            'queued_jobs'    => $queued_jobs,
            'failed_jobs'    => $failed_jobs,
            'php_extensions' => $php_extensions,
        ]);
    }
}
