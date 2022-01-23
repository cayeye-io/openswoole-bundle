<?php

namespace K911\Swoole\Coroutine;

use Swoole\Coroutine;

/**
 * PubSub Service which can be used within one process / worker to simplify communication between coroutines
 */
class CoroutinePubSub
{
    protected array $subscriptions = [];
    protected array $topicLookupTable = [];
    protected array $knownTopics = [];

    public function __construct()
    {
    }

    /**
     * Builds ob the $topicLookupTable in the form: topic => array of cids
     */
    public function updateTopicLookupTable()
    {
        $topicLookupTable = [];

        foreach (array_keys($this->knownTopics) as $topic) {
            $topicLookupTable[$topic] = [];

            foreach ($this->subscriptions as $cid => $subscriptionData) {
                foreach ($subscriptionData[0] as $topicsMatcher) {
                    if (preg_match("/$topicsMatcher/", $topic)) {
                        $topicLookupTable[$topic][] = $cid;
                    }
                }
            }
        }

        $this->topicLookupTable = $topicLookupTable;
    }

    /**
     * Publish a message to the given topic
     * @param string $topic
     * @param mixed $message
     */
    public function publish(string $topic, mixed $message)
    {
        if (!array_key_exists($topic, $this->knownTopics)) {
            $this->knownTopics[$topic] = 1;
            $this->updateTopicLookupTable();
        }

        foreach ($this->topicLookupTable[$topic] as $cid) {
            if (!array_key_exists($cid, $this->subscriptions)) {
                continue;
            }

            // if couroutine is finished or was canceled in which the subscription was taken, force unsubcribe
            if (!Coroutine::exists($cid)) {
                unset($this->subscriptions[$cid]);
                $this->updateTopicLookupTable();
                continue;
            }

            $this->subscriptions[$cid][1]($this, $topic, $message);
        }
    }

    /**
     * Subscribe to topics, where each entry can be a regular expression to subscribe to multiple topics.
     * The given callback function will be called with the parameters (CoroutinePubSub $pubsub, string $topic, mixed $message)
     * @param array $topics
     * @param callable $callback
     */
    public function subscribe(array $topics, callable $callback): void
    {
        $cid = Coroutine::getCid();
        $this->subscriptions[$cid] = [$topics, $callback];
        $this->updateTopicLookupTable();
    }


    /**
     * Unsubscribe from all topics and free resources
     * @throws \Exception
     */
    public function unsubscribe(): void
    {
        $cid = Coroutine::getCid();
        if (!array_key_exists($cid, $this->subscriptions)) {
            throw new \Exception("Could not unsubscribe coroutine #".$cid.". Did you subscribe in the same coroutine?");
        }
        unset($this->subscriptions[$cid]);
        $this->updateTopicLookupTable();
    }

}
