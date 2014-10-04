<?php

namespace Resque;

use Predis\ClientInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Resque\Exception\ResqueRuntimeException;
use Resque\Statistic\StatsInterface;
use Resque\Statistic\BlackHoleBackend as BlackHoleStats;

/**
 * Resque Foreman
 *
 * Handles creating, pruning, forking, killing and general management of workers.
 */
class Foreman implements LoggerAwareInterface
{
    /**
     * @var array
     */
    protected $workers;

    /**
     * @var array Workers currently registered in Redis as work() has been called.
     */
    protected $registeredWorkers;

    /**
     * @var ClientInterface Redis connection.
     */
    protected $redis;

    /**
     * @var StatsInterface
     */
    protected $statisticsBackend;

    /**
     * @var LoggerInterface Logging object that implements the PSR-3 LoggerInterface
     */
    protected $logger;

    public function __construct()
    {
        $this->workers = array();
        $this->registeredWorkers = array();
        $this->logger = new NullLogger();

        if (function_exists('gethostname')) {
            $this->hostname = gethostname();
        } else {
            $this->hostname = php_uname('n');
        }
    }

    /**
     * @param ClientInterface $redis
     * @return $this
     */
    public function setRedisBackend(ClientInterface $redis)
    {
        $this->redis = $redis;

        return $this;
    }

    /**
     * Set statistic backend
     *
     * @param StatsInterface $statisticsBackend
     * @return $this
     */
    public function setStatisticsBackend(StatsInterface $statisticsBackend)
    {
        $this->statisticsBackend = $statisticsBackend;

        return $this;
    }

    /**
     * @return StatsInterface
     */
    public function getStatisticsBackend()
    {
        if (null === $this->statisticsBackend) {
            $this->setStatisticsBackend(new BlackHoleStats());
        }

        return $this->statisticsBackend;
    }

    /**
     * Inject a logging object into the worker
     *
     * @param LoggerInterface $logger
     * @return null|void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Given a worker ID, find it and return an instantiated worker class for it.
     *
     * @param string $workerId The ID of the worker.
     * @return Worker Instance of the worker. null if the worker does not exist.
     */
    public function findWorkerById($workerId)
    {
        if (false /** === $this->exists($workerId) */ || false === strpos($workerId, ":")) {

            return null;
        }

        list($hostname, $pid, $queues) = explode(':', $workerId, 3);
        $queues = explode(',', $queues);

        $worker = new Worker();
        foreach ($queues as $queue) {
            $worker->addQueue(new Queue($queue));
        }

        $worker->setId($workerId);

        return $worker;
    }

    /**
     * Return all workers known to Resque as instantiated instances.
     * @return WorkerInterface[]
     */
    public function all()
    {
        $workers = $this->redis->smembers('workers');

        if (!is_array($workers)) {
            $workers = array();
        }

        $instances = array();
        foreach ($workers as $workerId) {
            $instances[] = $this->findWorkerById($workerId);
        }

        return $instances;
    }

    /**
     * Registers the given worker in Redis.
     *
     * @throws \Exception
     *
     * @param WorkerInterface $worker
     * @return $this
     */
    public function register(WorkerInterface $worker)
    {
        if (in_array($worker, $this->registeredWorkers, true)) {
            throw new \Exception('Cannot double register a worker, call deregister(), or halt() to clear');
        }

        $id = $worker->getId();

        $this->registeredWorkers[$id] = $worker;

        $this->redis->sadd('workers', $id);
        $this->redis->set('worker:' . $id . ':started', strftime('%a %b %d %H:%M:%S %Z %Y'));

        return $this;
    }

    /**
     * deregister the given worker from Redis.
     *
     * @param WorkerInterface $worker
     */
    public function deregister(WorkerInterface $worker)
    {
        $id = $worker->getId();

        $worker->shutdownNow();

        $this->redis->srem('workers', $id);
        $this->redis->del('worker:' . $id);
        $this->redis->del('worker:' . $id . ':started');
        $this->getStatisticsBackend()->clear('processed:' . $id);
        $this->getStatisticsBackend()->clear('failed:' . $id);

        unset($this->registeredWorkers[$id]);
    }

    /**
     * Given a worker, check if it is registered/valid.
     *
     * @param WorkerInterface $worker The worker.
     * @return boolean True if the worker exists in redis, false if not.
     */
    public function isRegistered(WorkerInterface $worker)
    {
        return (bool)$this->redis->sismember('workers', $worker->getId());
    }

    /**
     * @param Worker[] $workers An array of workers you would like forked into child processes and set on their way.
     * @param bool $wait If true, this Foreman will wait for the workers to complete. This will guarantee workers are
     *                   cleaned up after correctly, however this is not really practical for most purposes.
     */
    public function work($workers, $wait = false)
    {
        // @todo Guard multiple calls. Expect ->work() ->halt() ->work() etc
        // @todo Check workers are instanceof WorkerInterface.

        $this->redis->disconnect();

        /** @var Worker $worker */
        foreach ($workers as $worker) {
            $worker->setPid(self::fork());
            if (!$worker->getPid()) {
                // This is child process, it will work and then die.
                $this->register($worker);
                $worker->work();
                $this->deregister($worker);

                exit(0);
            }

            $this->logger->info(
                sprintf(
                    'Successfully started worker %s with pid %d',
                    $worker,
                    $worker->getPid()
                )
            );
        }

        if ($wait) {
            foreach ($workers as $worker) {
                $status = 0;
                if ($worker->getPid() != pcntl_waitpid($worker->getPid(), $status)) {
                    die("Error with worker wait on pid {$worker->getPid()}.\n"); // @todo Exception?
                } else {
                    $this->deregister($worker);
                }
            }
        }
    }

    /**
     * fork() helper method for php-resque
     *
     * @see pcntl_fork()
     *
     * @return int Return vars as per pcntl_fork()
     * @throws ResqueRuntimeException when cannot fork, or fork failed.
     */
    public static function fork()
    {
        if (!function_exists('pcntl_fork')) {
            throw new ResqueRuntimeException('pcntl_fork is not available');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new ResqueRuntimeException(
                sprintf(
                    'Unable to fork child. %s',
                    pcntl_strerror(pcntl_get_last_error())
                )
            );
        }

        return $pid;
    }

    /**
     * Look for any workers which should be running on this server and if
     * they're not, remove them from Redis.
     *
     * This is a form of garbage collection to handle cases where the
     * server may have been killed and the Resque workers did not die gracefully
     * and therefore leave state information in Redis.
     */
    public function pruneDeadWorkers()
    {
        $workerPids = $this->getLocalWorkerPids();
        $workers = $this->all();
        foreach ($workers as $worker) {
            if ($worker instanceof WorkerInterface) {
                $id = $worker->getId();
                list($host, $pid, $queues) = explode(':', $id, 3);
                if ($host != $this->hostname || in_array($pid, $workerPids) || $pid == getmypid()) {

                    continue;
                }
                $this->logger->warning('Pruning dead worker {worker}', array('worker' => $id));
                $this->deregister($worker);
            }
        }
    }

    /**
     * Local worker process IDs
     *
     * Return an array of process IDs for all of the Resque workers currently running on this machine.
     *
     * @return array An array of Resque worker process IDs.
     */
    public function getLocalWorkerPids()
    {
        $pids = array();
        exec('ps -A -o pid,command | grep [r]esque', $cmdOutput); // @todo The hard coded [r]esque is dangerous.
        foreach ($cmdOutput as $line) {
            list($pids[],) = explode(' ', trim($line), 2);
        }

        return $pids;
    }
}
