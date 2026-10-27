<?php
namespace lib\Task;

final class SystemdUnits
{
    public static function base(array $c) { return substr($c['service'],0,-8); }
    public static function names(array $c)
    {
        $base=self::base($c);
        return ['target'=>$base.'.target','slice'=>'epaytask'.substr($base,-12).'.slice','job'=>$base.'-job@.service','plugin'=>$base.'-plugin@.service'];
    }
    private static function process(array $c)
    {
        $names=self::names($c);
        return "User=".$c['user']."\nWorkingDirectory=".Installer::directory($c['root'])."\nSlice=".$names['slice'].
            "\nNoNewPrivileges=true\nUMask=0027\nKillMode=control-group\nKillSignal=SIGTERM\nSendSIGKILL=yes\n".
            "LogNamespace=".self::base($c)."\nStandardOutput=journal\nStandardError=journal\n";
    }
    public static function files(array $c)
    {
        $n=self::names($c);$q=[Installer::class,'quote'];$exec=$q($c['php'],true).' ';
        $part="PartOf=".$n['target']."\n";
        $worker="[Unit]\nDescription=Epay task scheduler\nAfter=network-online.target\nWants=network-online.target\n".$part.
            "StartLimitIntervalSec=120\nStartLimitBurst=5\n[Service]\nType=notify\nNotifyAccess=main\nWatchdogSec=30\nWatchdogSignal=SIGKILL\nTimeoutStartSec=40\n".str_replace('NoNewPrivileges=true','NoNewPrivileges=false',self::process($c)).
            "ExecStart=".$exec.$q($c['root'].'/bin/task-log.php',true)." worker\nRestart=on-failure\nRestartSec=5\nTimeoutStopSec=930\nMemoryMax=192M\nTasksMax=24\n";
        $template="[Unit]\nAfter=network-online.target\nBefore=".$c['service']."\n".$part;
        $job=$template."Description=Epay isolated task %i\n[Service]\nType=exec\n".self::process($c).
            "ExecStart=".$exec.$q($c['root'].'/bin/task-log.php',true)." job %i\nRestart=no\nRuntimeMaxSec=910\nTimeoutStopSec=5\nMemoryMax=256M\nTasksMax=32\n";
        $plugin=$template."Description=Epay plugin service %i\nStartLimitIntervalSec=120\nStartLimitBurst=5\n[Service]\nType=exec\n".self::process($c).
            "ExecStart=".$exec.$q($c['root'].'/bin/task-log.php',true)." plugin %i\nRestart=on-failure\nRestartPreventExitStatus=78\nRestartSec=5\nTimeoutStopSec=30\nMemoryMax=192M\nTasksMax=32\n";
        return [$c['service']=>$worker,$n['job']=>$job,$n['plugin']=>$plugin,
            $n['target']=>"[Unit]\nDescription=Epay tasks and plugin services\nWants=".$c['service']."\nAfter=network-online.target\n[Install]\nWantedBy=multi-user.target\n",
            $n['slice']=>"[Unit]\nDescription=Epay site background resource budget\n[Slice]\nMemoryAccounting=yes\nTasksAccounting=yes\nMemoryHigh=".$c['memory_high_mb']."M\nMemoryMax=".$c['memory_max_mb']."M\nTasksMax=192\nCPUAccounting=yes\n"];
    }
    public static function helper(array $c)
    {
        $n=self::names($c);$base=self::base($c);$q=[Installer::class,'shellQuote'];
        $script=<<<'SH'
#!/bin/sh
set -eu
[ "$#" -ge 1 ] && [ "$#" -le 2 ] || exit 64
action=$1
case "$action" in
 status) [ "$#" -eq 1 ] || exit 64; exec @CTL@ show --no-page --property=LoadState,ActiveState,SubState,MainPID,Result,NRestarts,StatusText,WatchdogUSec,WatchdogTimestamp @WORKER@ ;;
 target-status) [ "$#" -eq 1 ] || exit 64; exec @CTL@ show --no-page --property=LoadState,ActiveState,SubState,UnitFileState @TARGET@ ;;
 budget) [ "$#" -eq 1 ] || exit 64; exec @CTL@ show --no-page --property=MemoryCurrent,MemoryMax,TasksCurrent,TasksMax @SLICE@ ;;
 start|stop|restart|enable|disable) [ "$#" -eq 1 ] || exit 64; exec @CTL@ --no-block "$action" @TARGET@ ;;
 job-start|job-stop|job-status)
   [ "$#" -eq 2 ] || exit 64
   id=$2
   [ "${#id}" -eq 32 ] || exit 64
   case "$id" in *[!a-f0-9]*) exit 64 ;; esac
   unit=@JOB@"$id"'.service' ;;
 plugin-start|plugin-stop|plugin-status)
   [ "$#" -eq 2 ] || exit 64
   id=$2
   prefix=${id%g*}
   gen=${id##*g}
   [ "${#prefix}" -eq 32 ] || exit 64
   case "$prefix" in *[!a-f0-9]*) exit 64 ;; esac
   case "$gen" in ''|0*|*[!0-9]*) exit 64 ;; esac
   [ "${#gen}" -le 9 ] || exit 64
   unit=@PLUGIN@"$id"'.service' ;;
 *) exit 64 ;;
esac
case "$action" in
 *-start) @CTL@ is-active --quiet @TARGET@ || exit 69; exec @CTL@ --no-block start "$unit" ;;
 *-stop) exec @CTL@ --no-block stop "$unit" ;;
 *-status) @CTL@ show --no-page --property=LoadState,ActiveState,SubState,Result,MainPID,ExecMainStatus,NRestarts,MemoryCurrent,TasksCurrent "$unit" || test "$?" -eq 4 ;;
 *) exit 64 ;;
esac
SH;
        return strtr($script,['#!/bin/sh'=>'#!'.$c['sh'],'@CTL@'=>$q($c['systemctl']),
            '@WORKER@'=>$q($c['service']),'@TARGET@'=>$q($n['target']),
            '@SLICE@'=>$q($n['slice']),'@JOB@'=>$q($base.'-job@'),'@PLUGIN@'=>$q($base.'-plugin@')])."\n";
    }
}
