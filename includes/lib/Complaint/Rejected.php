<?php
namespace lib\Complaint;
final class Rejected extends \RuntimeException
{
    public $upstreamCode;
    public function __construct($message,$upstreamCode='')
    {
        parent::__construct($message);
        $this->upstreamCode=(string)$upstreamCode;
    }
}
