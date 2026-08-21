<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\Ping;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Scaffolding (#8): dispatches a Ping so the async seam can be exercised by hand
 * (`--fail` drives the retry + failure transport). Deleted in #5.
 */
#[AsCommand(name: 'app:ping', description: 'Dispatch a scaffolding Ping onto the async transport')]
final class PingCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Text echoed by the handler', 'ping')
            ->addOption('fail', null, InputOption::VALUE_NONE, 'Make the handler throw, to exercise retry/failure');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $note = (string) $input->getOption('note');
        $fail = (bool) $input->getOption('fail');

        $this->bus->dispatch(new Ping($note, $fail));

        $io->success(sprintf('Ping dispatched (note="%s", fail=%s). Watch `make worker-logs`.', $note, $fail ? 'true' : 'false'));

        return Command::SUCCESS;
    }
}
