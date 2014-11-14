<?php

namespace Resque\Component\Job;

/**
 * Contains all job related events
 */
final class ResqueJobEvents
{
    /**
     * The STATE_CHANGE event is dispatched each time a Model\TrackableJobInterface
     * has it's state changed by a worker.
     *
     * The event listener receives a Resque\Component\Job\Event\JobEvent instance.
     *
     * @var string
     */
    const STATE_CHANGE = 'resque.job.state_change';

    const PRE_PERFORM = 'resue.job.pre_perform';

    /**
     * The PERFORMED event is dispatched whenever a job successfully performs from with in a worker.
     *
     * The event listener receives a Resque\Component\Job\Event\JobEvent instance.
     */
    const PERFORMED = 'resque.job.performed';

    /**
     * The FAILED event is dispatched whenever a job fails to perform with in a worker. The cause may be from
     * a worker child dirty exit, or an uncaught exception from with in the job itself.
     *
     * The event listener receives a Resque\Component\Job\Event\JobFailedEvent instance.
     *
     * @var string
     */
    const FAILED = 'resque.job.failed';
}