<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Security;
use AllStore\Core\Request;
use AllStore\Core\AppException;
use AllStore\Core\Logger;

final class SupportService
{
    public static function createTicket(?string $userId, string $guestEmail, array $in, ?array $attachment = null): string
    {
        if (!Security::rateLimit('ticket:' . Request::ip(), 6, 3600)) { throw new AppException('Too many requests. Please try again later.', 429); }
        $subject = mb_substr(trim((string)($in['subject'] ?? '')),0,180);
        $message = mb_substr(trim((string)($in['message'] ?? '')),0,4000);
        $email = strtolower(trim((string)($in['email'] ?? $guestEmail)));
        if (mb_strlen($subject) < 4 || mb_strlen($message) < 10 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException('Please provide a subject, a message and a valid email.', 422);
        }
        $category = in_array($in['category'] ?? '', ['general','order','returns','payment','technical','other'], true) ? $in['category'] : 'general';
        $priority = in_array($in['priority'] ?? '', ['low','normal','high','urgent'], true) ? $in['priority'] : 'normal';
        $attachmentUrl = null;
        if ($attachment && ($attachment['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $attachmentUrl = UploadService::store($attachment, true, true);
        }
        $id = Helpers::uuid(); $number = Helpers::ticketNumber();
        Database::tx(function () use ($id,$number,$userId,$email,$subject,$category,$priority,$message,$attachmentUrl): void {
            Database::insert('support_tickets', ['id'=>$id,'ticket_number'=>$number,'user_id'=>$userId,'guest_email'=>$email,
                'subject'=>$subject,'category'=>$category,'priority'=>$priority,'status'=>'open',
                'created_at'=>Helpers::now(),'updated_at'=>Helpers::now()]);
            Database::insert('ticket_messages', ['id'=>Helpers::uuid(),'ticket_id'=>$id,'sender_id'=>$userId,
                'sender_role'=>'customer','message'=>$message,'attachment_url'=>$attachmentUrl,'created_at'=>Helpers::now()]);
            Database::insert('notifications', ['id'=>Helpers::uuid(),'user_id'=>null,'admin_only'=>1,'type'=>'support_ticket',
                'title'=>'New support ticket '.$number,'body'=>mb_substr($subject,0,120),'data'=>'{}','created_at'=>Helpers::now()]);
        });
        Logger::audit('ticket.created','support_tickets',$id);
        return $number;
    }
    public static function ticketForUser(string $id, string $userId): ?array
    {
        return Database::first('SELECT * FROM support_tickets WHERE id=:i AND user_id=:u', ['i'=>$id,'u'=>$userId]);
    }
    public static function ticketsForUser(string $userId): array
    {
        return Database::all('SELECT * FROM support_tickets WHERE user_id=:u ORDER BY created_at DESC', ['u'=>$userId]);
    }
    public static function messages(string $ticketId): array
    {
        return Database::all('SELECT * FROM ticket_messages WHERE ticket_id=:t ORDER BY created_at ASC', ['t'=>$ticketId]);
    }
    public static function addMessage(string $ticketId, ?string $senderId, string $role, string $message, ?array $attachment = null): void
    {
        $message = mb_substr(trim($message),0,4000);
        if (mb_strlen($message) < 1) { throw new AppException('Message cannot be empty.', 422); }
        $url = null;
        if ($attachment && ($attachment['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { $url = UploadService::store($attachment, true, true); }
        Database::tx(function () use ($ticketId,$senderId,$role,$message,$url): void {
            Database::insert('ticket_messages', ['id'=>Helpers::uuid(),'ticket_id'=>$ticketId,'sender_id'=>$senderId,
                'sender_role'=>$role,'message'=>$message,'attachment_url'=>$url,'created_at'=>Helpers::now()]);
            Database::update('support_tickets', ['status'=>$role === 'admin' ? 'pending' : 'open','updated_at'=>Helpers::now()], 'id=:i', ['i'=>$ticketId]);
        });
    }
    public static function allForAdmin(?string $status = null): array
    {
        $w = $status ? 'WHERE t.status=:s' : '';
        $p = $status ? ['s'=>$status] : [];
        return Database::all("SELECT t.*, (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id=t.id) AS message_count
            FROM support_tickets t $w ORDER BY t.created_at DESC LIMIT 200", $p);
    }
    public static function updateStatus(string $id, string $status): void
    {
        if (!in_array($status, ['open','pending','resolved','closed'], true)) { throw new AppException('Invalid status.', 422); }
        Database::update('support_tickets', ['status'=>$status,'updated_at'=>Helpers::now(),
            'closed_at'=>$status === 'closed' ? Helpers::now() : null], 'id=:i', ['i'=>$id]);
        Logger::audit('ticket.status','support_tickets',$id,['status'=>$status]);
    }
    public static function contactMessage(array $in): void
    {
        if (!Security::rateLimit('contact:' . Request::ip(), 4, 3600)) { throw new AppException('Too many messages. Please try again later.', 429); }
        $name = mb_substr(trim((string)($in['name'] ?? '')),0,120);
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $subject = mb_substr(trim((string)($in['subject'] ?? '')),0,180);
        $message = mb_substr(trim((string)($in['message'] ?? '')),0,4000);
        if (mb_strlen($name) < 2 || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($subject) < 3 || mb_strlen($message) < 10) {
            throw new AppException('Please complete all fields with valid information.', 422);
        }
        Database::insert('contact_messages', ['id'=>Helpers::uuid(),'name'=>$name,'email'=>$email,'subject'=>$subject,
            'message'=>$message,'ip_address'=>Request::ip(),'created_at'=>Helpers::now()]);
        Database::insert('notifications', ['id'=>Helpers::uuid(),'user_id'=>null,'admin_only'=>1,'type'=>'contact',
            'title'=>'New contact message','body'=>mb_substr($subject,0,120),'data'=>'{}','created_at'=>Helpers::now()]);
        Logger::audit('contact.submitted','contact_messages');
    }
}
