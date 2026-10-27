<?php
namespace lib\Complaint;
/** A transport/server failure that may be retried by read-only synchronization. */
final class TransientFailure extends \RuntimeException {}
