<?php
namespace lib\Task;

/** A cooperative stop is a successful exit, not a configuration fault. */
final class ServiceStopped extends \RuntimeException {}
