<?php

// reference for: use OCA\Analytics\Datasource\DatasourceEvent;
namespace OCA\Analytics\Datasource {
	use OCP\EventDispatcher\Event;

	interface DatasourceEvent extends Event {
		public function registerDatasource(string $datasource): void;
		public function getDataSources(): array;
	}
	interface IDatasource {
		public function getName(): string;
		public function getId(): int;
		public function getTemplate(): array;
		public function readData($option): array;
	}
}
