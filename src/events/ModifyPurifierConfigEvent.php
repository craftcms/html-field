<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\htmlfield\events;

use HTMLPurifier_Config;
use yii\base\Event;

/**
 * ModifyPurifierConfig class.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0.0
 */
class ModifyPurifierConfigEvent extends Event
{
    /**
     * @var HTMLPurifier_Config|null $config the HTML Purifier config
     */
    public ?HTMLPurifier_Config $config;
}

class_alias(ModifyPurifierConfigEvent::class, \craft\redactor\events\ModifyPurifierConfigEvent::class);
class_alias(ModifyPurifierConfigEvent::class, \craft\ckeditor\events\ModifyPurifierConfigEvent::class);
