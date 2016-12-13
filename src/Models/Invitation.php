<?php
namespace ZiNETHQ\SparkInvite\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Spark\Spark;
use Carbon\Carbon;
use Event;
use Log;
use Password;

class Invitation extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_SUCCESSFUL =  'successful';
    const STATUS_CANCELLED = 'canceled';
    const STATUS_EXPIRED = 'expired';
    const STATUS = [ self::STATUS_PENDING, self::STATUS_SUCCESSFUL, self::STATUS_CANCELLED, self::STATUS_EXPIRED ];

    public $timestamps = true;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_invitations';
    protected $with = ['referralTeam', 'referralUser', 'invitee'];
    protected $hidden = ['old_password'];

    /**
     * Obtain an invitation by it's token
     */
    public static function get($token)
    {
        return self::where('token', $token)->first();
    }

    /**
     * Obtain invitations by their referral team
     */
    public static function getByReferralTeam($referralTeam, $status = null)
    {
        return self::getByParticipant('referral_team_id', $referralTeam->id, $status);
    }

    /**
     * Obtain invitation by their referral team
     */
    public static function getByReferralUser($referralUser, $status = null)
    {
        return self::getByParticipant('referral_user_id', $referralUser->id, $status);
    }

    /**
     * Obtain invitations by their invitee
     */
    public static function getByInvitee($invitee, $status = null)
    {
        return self::getByParticipant('invitee_id', $invitee->id, $status);
    }

    protected static function getByParticipant($column, $id, $status = null)
    {
        $query = self::where($column, $id);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->latest()->get();
    }

    /**
     * Referral Team
     */
    public function referralTeam()
    {
        return $this->belongsTo(Spark::teamModel(), 'referral_team_id');
    }

    /**
     * Referral User
     */
    public function referralUser()
    {
        return $this->belongsTo(Spark::userModel(), 'referral_user_id');
    }

    /**
     * Invitee
     */
    public function invitee()
    {
        return $this->belongsTo(Spark::userModel(), 'invitee_id');
    }

    public function cancel()
    {
        if ($this->isExpired()) {
            return false;
        }

        if (!$this->isPending()) {
            Log::warning("Attempted to cancel an invitation for user {$this->invitee_id} that has the {$this->status} status.");
            return false;
        }

        $this->status = self::STATUS_CANCEL;
        $this->old_password = null;
        $this->save();

        $this->publishEvent('cancelled');

        return true;
    }

    public function accept()
    {
        if ($this->isExpired()) {
            return false;
        }

        if (!$this->isPending()) {
            Log::warning("Attempted to accept an invitation for user {$this->invitee_id} that has the {$this->status} status.");
            return false;
        }

        $this->publishEvent('accepted');

        // Auth::guard()->login($this->invitee());

        return Password::broker()->createToken($this->invitee);
    }

    public function validateStatus()
    {
        if ($this->status === self::STATUS_PENDING) {
            if ($this->old_password && $this->invitee->password !== $this->old_password) {
                $this->status = self::STATUS_SUCCESSFUL;
                $this->token = null;
                $this->old_password = null;
                $this->save();
                $this->publishEvent('successful');
                return;
            }

            if (Carbon::now()->diffInHours($this->created_at) >= config('sparkinvite.expires')) {
                $this->status = self::STATUS_EXPIRED;
                $this->token = null;
                $this->old_password = null;
                $this->save();
                $this->publishEvent('expired');
                return;
            }
        }
    }

    /*
    |----------------------------------------------------------------------
    | Magic Methods
    |----------------------------------------------------------------------
    */
    /**
     * Magic __call method to handle dynamic methods.
     *
     * @param  string $method
     * @param  array  $arguments
     * @return mixed
     */
    public function __call($method, $arguments = array())
    {
        // Handle isStatus() methods
        if (starts_with($method, 'is') && $method !== 'is') {
            $status = strtolower(substr($method, 2));

            $this->validateStatus();

            return $this->status === $status;
        }

        return parent::__call($method, $arguments);
    }

    /*
    |----------------------------------------------------------------------
    | Private Methods
    |----------------------------------------------------------------------
    */

    /**
     * Fire Laravel event
     * @param  string $event event name
     */
    private function publishEvent($eventKey)
    {
        Event::fire(config('sparkinvite.event.prefix').".{$eventKey}", [
            'event' => $eventKey,
            'invitation' => $this
        ], false);
    }
}
