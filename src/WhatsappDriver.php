<?php

namespace BotMan\Drivers\Whatsapp;

use BotMan\Drivers\Whatsapp\Extensions\User;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Interfaces\DriverInterface;
use BotMan\BotMan\Interfaces\UserInterface;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsappDriver extends HttpDriver
{
    /**
     * @const string
     */
    const DRIVER_NAME = 'Whatsapp';
    /**
     * @var string
     */
    protected $endpoint = 'sendMessage';
    /**
     * @var array
     */
    protected $messages = [];
    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        return !is_null($this->payload->get('instanceId'));
    }

    /**
     * Retrieve the chat message(s).
     *
     * @return array
     */
    public function getMessages()
    {
        if (empty($this->messages)) {
            $this->loadMessages();
        }
        return $this->messages;
    }

    /**
     * @return void
     */
    protected function loadMessages()
    {
        if ($this->payload->get('messages') !== null) {
            $messages = collect($this->payload->get('messages'))
                ->filter(function($value) {
                    return !$value['fromMe'];
                })
                ->map(function($value) {
                    $message = new IncomingMessage($value['body'], $value['author'], $value['chatId'], $this->payload);
                    $message->addExtras('userName', $value['senderName']);
                    return $message;
                })->toArray();
        }

        $this->messages = $messages ?? [];
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->config->get('url')) && !empty($this->config->get('token'));
    }

    /**
     * Retrieve User information.
     * @param IncomingMessage $matchingMessage
     * @return UserInterface
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        return new User($matchingMessage->getPayload()['author'], $matchingMessage->getSender(), null, null,
            ['fromMe' => $matchingMessage->getPayload()['fromMe']]);
    }

    /**
     * @param IncomingMessage $message
     * @return \BotMan\BotMan\Messages\Incoming\Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        return Answer::create($message->getText())->setMessage($message);
    }

    /**
     * @param string|\BotMan\BotMan\Messages\Outgoing\Question $message
     * @param IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return array
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        return [
            'chatId' => $matchingMessage->getRecipient(),
            'body' => $message->getText()
        ];
    }

    /**
     * @param mixed $payload
     * @return Response
     */
    public function sendPayload($payload)
    {
        if ($this->payload['driver'] == self::DRIVER_NAME) {
            $messages = $this->buildReply($this->replies);

            // Reset replies
            $this->replies = [];

            $req = json_encode([
                'status' => $this->replyStatusCode,
                'messages' => $messages,
                'client' => json_decode($this->payload['client'])
            ]);

            \App\Log::insert(['Type' => 'HandleChat whatsapp before ', 'content' => "Api" . $req]);
            $ch = json_decode($req);
            if (!empty($ch->messages[0]->additionalParameters->contact_phone)) {
//            dd('dd');
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => "https://victorylink.yourwhatsapp.com:4000",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "POST",
                    CURLOPT_POSTFIELDS => $req,
                    CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
                ]);

                $response = curl_exec($curl);
                $err = curl_error($curl);

                curl_close($curl);
                if ($err) {
                    echo "cURL Error #:" . $err;
                    \App\Log::insert(['Type' => 'HandleChat whatsapp Error', 'content' => "cURL Error #:" . $err]);

                } else {
                    \App\Log::insert(['Type' => 'HandleChat whatsapp success', 'content' => "Done" . $response]);
                    echo 'Done send whatsapp';
                }
            }
        }

//        return $this->http->post($this->buildApiUrl($this->endpoint), [], $payload);
    }

    /**
     * @param Request $request
     * @return void
     */
    public function buildPayload(Request $request)
    {
        $this->payload = new ParameterBag((array) json_decode($request->getContent(), true));
        $this->event = Collection::make((array) $this->payload->get('messages'));
//        $this->signature = $request->headers->get('X_HUB_SIGNATURE', '');
        $this->content = $request->getContent();
        $this->config = Collection::make($this->config->get('whatsapp', []));
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $parameters
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return void
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $parameters = array_replace_recursive([
            'chatId' => $matchingMessage->getRecipient(),
        ], $parameters);

        return $this->http->post($this->buildApiUrl($endpoint), [], $parameters);
    }

    /**
     * @param $endpoint
     * @return string
     */
    protected function buildApiUrl($endpoint)
    {
        return $this->config->get('url') . $endpoint . '?token=' . $this->config->get('token');
    }


}
