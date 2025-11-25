<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = ['hostname', 'ip_addr', 'type', 'description', 'sections', 'snmp_community', 'snmp_version', 'snmp_port', 'snmp_timeout', 'snmp_queries', 'snmp_v3_sec_level', 'snmp_v3_auth_protocol', 'snmp_v3_auth_pass', 'snmp_v3_priv_protocol', 'snmp_v3_priv_pass', 'snmp_v3_ctx_name', 'snmp_v3_ctx_engine_id', 'rack_id', 'rack_start', 'rack_size', 'location_id'];
    
    public function location() { return $this->belongsTo(Location::class); }
}
