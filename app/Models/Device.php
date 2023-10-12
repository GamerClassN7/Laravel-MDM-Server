<?php

namespace App\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Device extends Model
{
    use HasFactory;

    public function getDataAttribute($value)
    {
        return (json_decode($value) ?? []);
    }

    public function getDrivesAttribute($value)
    {
        if ([] === $this->data) {
            return [];
        }

        $drives =json_decode(json_encode($this->data->machine), true)["Drives"];
        foreach ( $drives  as $key => $drive) {
            $drive = (array)$drive;
            if ($drive['Size'] <= 0) {
                continue;
            }

            $usedSpace = (int) $drive['Size'] - (int) $drive['SizeRemaining'];
            $drives[$key]['PercentUsed'] = round($usedSpace / ((int) $drive['Size'] / 100));
        }
        return $drives;
    }

    public function setDrivesAttribute($value)
    {
        $this->attributes['drives'] = json_encode($value);
    }

    public function getCommandsAttribute($value)
    {
        return json_decode($value);
    }

    public function setCommandsAttribute($value)
    {
        $this->attributes['commands'] = json_encode((array) $value);
    }

    public function getDisplayNameAttribute()
    {
        $name = $this->friendly_name;
        if (empty($name)) {
            $name = $this->name;
        }
        return $name;
    }

    public function getNiceUptimeAttribute()
    {
        if (isset($this->data->machine->uptime)) {
            return CarbonInterval::seconds(json_decode($this->data)->machine->uptime)->cascade()->forHumans();
        }
        return false;
    }

    public function getOfflineAttribute()
    {
        // if (isset(json_decode($this->data)->settings->timeout)) {
        //     if (json_decode($this->data)->settings->timeout >= $this->updated_at->diffInSeconds()) {
        //         return false;
        //     }
        // }
        if (900 >= $this->updated_at->diffInSeconds()) {
            return false;
        }

        return true;
    }

    public function getRestartPendingAttribute()
    {
        if (null !== $this->data->machine->RestartRequired) {
            if (filter_var($this->data->machine->RestartRequired, FILTER_VALIDATE_BOOLEAN) === true) {
                return true;
            }
        }

        return false;
    }

    public function getLastLogonUserAttribute()
    {
        if (isset($this->data->machine->last_logon_user)) {
            return $this->data->machine->last_logon_user;
        }
        return false;
    }

    public function getAppsPackagesUpdatesAttribute()
    {
        if (isset($this->data->packages_updates)) {
            return $this->data->packages_updates;
        }
        return [];
    }

    public function getUpdatesAttribute()
    {
        if (isset($this->data->os_updates)) {
            return $this->data->os_updates;
        }
        return [];
    }

    public function getNetworksAttribute()
    {
        if (isset($this->data->machine->IPAddresses)) {
            return $this->data->machine->IPAddresses;
        }
        return [];
    }
}
