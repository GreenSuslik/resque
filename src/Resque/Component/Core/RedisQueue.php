<?php

namespace Resque\Component\Core;

use Resque\Component\Core\Redis\RedisClientAwareInterface;
use Resque\Component\Core\Redis\RedisClientInterface;
use Resque\Component\Job\Model\FilterableJobInterface;
use Resque\Component\Job\Model\Job;
use Resque\Component\Job\Model\JobInterface;
use Resque\Component\Queue\Model\AbstractQueue;
use Resque\Component\Queue\Model\OriginQueueAwareInterface;

/**
 * Resque Redis queue
 *
 * Uses redis to store the queue.
 */
class RedisQueue extends AbstractQueue implements RedisClientAwareInterface
{
    /**
     * @var RedisClientInterface A redis connection.
     */
    protected $redis;

    /**
     * @param RedisClientInterface $redis
     */
    public function __construct(RedisClientInterface $redis)
    {
        if (null !== $redis) {
            $this->setRedisClient($redis);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setRedisClient(RedisClientInterface $redis)
    {
        $this->redis = $redis;

        return $this;
    }

    /**
     * @return string Key name for redis.
     */
    protected function getRedisKey()
    {
        return 'queue:' . $this->getName();
    }

    /**
     * Push a job into the queue
     *
     * It should also make sure the queue is registered.
     *
     * @todo throw a exception when it fails!
     *
     * @param JobInterface $job The Job to enqueue.
     * @return bool True if successful, false otherwise.
     */
    public function push(JobInterface $job)
    {
        $result = $this->redis->rpush(
            $this->getRedisKey(),
            $job->encode()
        );

        return $result === 1;
    }

    /**
     * Pop a job from the queue
     *
     * @return JobInterface|null Decoded job from the queue, or null if no jobs.
     */
    public function pop()
    {
        $payload = $this->redis->lpop($this->getRedisKey());

        if (!$payload) {
            return null;
        }

        $job = Job::decode($payload);

        if ($job instanceof OriginQueueAwareInterface) {
            $job->setOriginQueue($this);
        }

        return $job;
    }

    /**
     * Remove jobs matching the filter
     *
     * @param array $filter
     * @return int The number of jobs removed
     */
    public function remove($filter = array())
    {
        $jobsRemoved = 0;

        $queueKey = $this->getRedisKey();
        $tmpKey = $queueKey . ':removal:' . time() . ':' . uniqid();
        $enqueueKey = $tmpKey . ':enqueue';

        // Move each job from original queue to a temporary list and leave
        while (\true) {
            $payload = $this->redis->rpoplpush($queueKey, $tmpKey);
            if (!empty($payload)) {
                $job = Job::decode($payload); // @todo should be something like $this->jobEncoderThingy->decode()
                if ($job instanceof FilterableJobInterface && $job::matchFilter($job, $filter)) {
                    $this->redis->rpop($tmpKey);
                    $jobsRemoved++;
                } else {
                    $this->redis->rpoplpush($tmpKey, $enqueueKey);
                }
            } else {
                break;
            }
        }

        // Move back from enqueue list to original queue
        while (\true) {
            $payload = $this->redis->rpoplpush($enqueueKey, $queueKey);
            if (empty($payload)) {
                break;
            }
        }

        $this->redis->del($tmpKey);
        $this->redis->del($enqueueKey);

        return $jobsRemoved;
    }

    /**
     * Return the number of pending jobs in the queue
     *
     * @return int The size of the queue.
     */
    public function count()
    {
        return $this->redis->llen($this->getRedisKey());
    }

    public function __toString()
    {
        return $this->getName();
    }
}
