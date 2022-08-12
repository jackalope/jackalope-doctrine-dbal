<?php declare(strict_types=1);

namespace Jackalope\Test\Fixture;

if (PHP_VERSION_ID >= 80000) {
    trait XMLDocumentTrait
    {
        /**
         * Dumps the internal XML tree back into a file.
         */
        #[\ReturnTypeWillChange]
        public function save(string $filename = null, int $options = 0): XMLDocument
        {
            return $this->doSave($filename);
        }
    }
} else {
    trait XMLDocumentTrait
    {
        public function save($file = null)
        {
            return $this->doSave($file);
        }
    }
}
