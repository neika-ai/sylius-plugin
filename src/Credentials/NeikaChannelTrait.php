<?php

declare(strict_types=1);

namespace Neika\SyliusPlugin\Credentials;

use Doctrine\ORM\Mapping\Column;

trait NeikaChannelTrait
{
    /**
     * @Column(name="neika_site_key", type="string", length=64, nullable=true)
     */
    #[Column(name: 'neika_site_key', type: 'string', length: 64, nullable: true)]
    private ?string $neikaSiteKey = null;

    /**
     * @Column(name="neika_api_secret_hash", type="string", length=64, nullable=true, options={"fixed": true})
     */
    #[Column(name: 'neika_api_secret_hash', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $neikaApiSecretHash = null;

    /**
     * @Column(name="neika_ingest_secret", type="string", length=128, nullable=true)
     */
    #[Column(name: 'neika_ingest_secret', type: 'string', length: 128, nullable: true)]
    private ?string $neikaIngestSecret = null;

    public function getNeikaSiteKey(): ?string
    {
        return $this->neikaSiteKey;
    }

    public function setNeikaSiteKey(?string $neikaSiteKey): void
    {
        $this->neikaSiteKey = $neikaSiteKey;
    }

    public function setNeikaApiSecret(#[\SensitiveParameter] string $plainApiSecret): void
    {
        $this->neikaApiSecretHash = hash('sha256', $plainApiSecret);
    }

    public function getNeikaIngestSecret(): ?string
    {
        return $this->neikaIngestSecret;
    }

    public function setNeikaIngestSecret(#[\SensitiveParameter] ?string $neikaIngestSecret): void
    {
        $this->neikaIngestSecret = $neikaIngestSecret;
    }
}
