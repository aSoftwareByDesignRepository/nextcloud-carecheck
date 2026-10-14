<?php

declare(strict_types=1);

namespace OCA\CareCheck\Command;

use OCA\CareCheck\Service\JournalService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VerifyChainCommand extends Command
{
	public function __construct(
		private IDBConnection $db,
		private JournalService $journal,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('carecheck:verify-chain')
			->setDescription('Verify the per-client journal hash chains')
			->addArgument('client', InputArgument::OPTIONAL, 'client id (default: all)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$clientArg = $input->getArgument('client');
		if ($clientArg !== null) {
			$bad = $this->journal->verify((int)$clientArg);
			$bad === 0 ? $output->writeln("<info>client {$clientArg}: chain intact</info>")
				: $output->writeln("<error>client {$clientArg}: chain broken at seq {$bad}</error>");
			return $bad === 0 ? 0 : 1;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('client_id')->from('cck_journal')->groupBy('client_id');
		$fail = 0;
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$bad = $this->journal->verify((int)$row['client_id']);
			if ($bad !== 0) {
				$output->writeln("<error>client {$row['client_id']}: chain broken at seq {$bad}</error>");
				$fail++;
			}
		}
		if ($fail === 0) {
			$output->writeln('<info>all chains intact</info>');
		}
		return $fail === 0 ? 0 : 1;
	}
}
