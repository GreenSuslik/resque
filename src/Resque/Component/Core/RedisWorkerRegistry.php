<?php

namespace Resque\Component\Core;

use Resque\Component\Core\Redis\RedisClientAwareInterface;
use Resque\Component\Core\Redis\RedisClientInterface;
use Resque\Component\Queue\Model\OriginQueueAwareInterface;
use Resque\Component\Worker\Event\WorkerEvent;
use Resque\Component\Worker\Factory\WorkerFactoryInterface;
use Resque\Component\Worker\Model\WorkerInterface;
use Resque\Component\Worker\Registry\WorkerRegistryInterface;
use Resque\Component\Worker\ResqueWorkerEvents;
use Resque\Component\Worker\Worker;

/**
 * Resque redis worker registry
 */
class RedisWorkerRegistry implements WorkerRegistryInterface, RedisClientAwareInterface
{
    /**
     * @var WorkerFactoryInterface
     */
    protected $workerFactory;

    /**
     * @var RedisClientInterface Redis connection.
     */
    protected $redis;

    public function __construct(RedisClientInterface $redis, WorkerFactoryInterface $workerFactory)
    {
        $this->setRedisClient($redis);
        $this->workerFactory = $workerFactory;
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
     * {@inheritDoc}
     */
    public function register(WorkerInterface $worker)
    {
        if ($this->isRegistered($worker)) {
            throw new \Exception('Cannot double register a worker, deregister it before calling register again');
        }

        $id = $worker->getId();
        $this->redis->sadd('workers', $id);
        $this->redis->set('worker:' . $id . ':started', date('c'));

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function isRegistered(WorkerInterface $worker)
    {
        return (bool)$this->redis->sismember('workers', $worker->getId());
    }

    /**
     * {@inheritDoc}
     */
    public function deregister(WorkerInterface $worker)
    {
        $id = $worker->getId();

        $worker->halt();

        $this->redis->srem('workers', $id);
        $this->redis->del('worker:' . $id);
        $this->redis->del('worker:' . $id . ':started');

        // @todo restore
        //$this->eventDispatcher->dispatch(ResqueWorkerEvents::UNREGISTERED, new WorkerEvent($worker));

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function all()
    {
        $workerIds = $this->redis->smembers('workers');

        if (!is_array($workerIds)) {
            return array();
        }

        $instances = array();
        foreach ($workerIds as $workerId) {
            $instances[] = $this->workerFactory->createWorkerFromId($workerId);
        }

        return $instances;
    }

    /**
     * {@inheritDoc}
     */
    public function count()
    {
        return $this->redis->scard('workers');
    }

    /**
     * {@inheritDoc}
     */
    public function findWorkerById($workerId)
    {
        $worker = $this->workerFactory->createWorkerFromId($workerId);

        if (false === $this->isRegistered($worker)) {

            return null;
        }

        return $worker;
    }

    /**
     * {@inheritDoc}
     */
    public function persist(WorkerInterface $worker)
    {
        $currentJob = $worker->getCurrentJob();

        if (null === $currentJob) {
            $this->redis->del('worker:' . $worker->getId());

            return $this;
        }

        $payload = json_encode(
            array(
                'queue' => ($currentJob instanceof OriginQueueAwareInterface) ? $currentJob->getOriginQueue() : null,
                'run_at' => date('c'),
                'payload' => $currentJob->encode($currentJob),
            )
        );

        $this->redis->set('worker:' . $worker->getId(), $payload);

        return $this;
    }
}