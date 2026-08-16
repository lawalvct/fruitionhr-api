<?php

namespace App\Modules\Auth\Support;

/**
 * Generates readable temporary passwords like "copper-mango-river-482".
 *
 * These are handed to a person to type once (often read out over the phone),
 * so symbol soup is the wrong trade — it gets mistyped and re-requested. All
 * lowercase, no ambiguous characters, hyphen separated.
 *
 * Roughly 35 bits of entropy (three words from 200, plus three digits). That
 * is deliberately weaker than a permanent password and is only appropriate
 * because these are short-lived, rate limited at login, and the recipient is
 * told to change them.
 */
class MemorablePassword
{
    /**
     * Plain, unambiguous nouns and adjectives. Kept free of words that sound
     * alike, read rudely in combination, or are awkward to spell aloud.
     *
     * @var list<string>
     */
    private const WORDS = [
        'amber', 'anchor', 'apple', 'arrow', 'autumn', 'basket', 'beacon', 'bishop',
        'blossom', 'bottle', 'branch', 'brave', 'bridge', 'bright', 'bronze', 'brook',
        'bubble', 'bundle', 'butter', 'cabin', 'cactus', 'camera', 'candle', 'canvas',
        'canyon', 'carbon', 'carpet', 'castle', 'cedar', 'chapel', 'cherry', 'chorus',
        'cinder', 'circle', 'citrus', 'clever', 'cliff', 'cloud', 'clover', 'cobalt',
        'coffee', 'comet', 'compass', 'copper', 'coral', 'cotton', 'crane', 'crayon',
        'crimson', 'crystal', 'cyclone', 'daisy', 'dawn', 'delta', 'desert', 'diamond',
        'dolphin', 'domino', 'dragon', 'dune', 'eagle', 'ember', 'emerald', 'engine',
        'falcon', 'feather', 'fern', 'fiddle', 'flame', 'flint', 'flower', 'forest',
        'fossil', 'fountain', 'garden', 'ginger', 'glacier', 'granite', 'grape', 'gravel',
        'guitar', 'hammer', 'harbour', 'harvest', 'hazel', 'heather', 'hollow', 'honey',
        'horizon', 'indigo', 'island', 'ivory', 'jacket', 'jasmine', 'jungle', 'juniper',
        'kettle', 'lagoon', 'lantern', 'laurel', 'lemon', 'lily', 'linen', 'lotus',
        'lumber', 'maple', 'marble', 'marigold', 'meadow', 'melon', 'mango', 'mantle',
        'maroon', 'meteor', 'mint', 'mirror', 'monsoon', 'moss', 'mountain', 'mulberry',
        'nectar', 'noble', 'nutmeg', 'oasis', 'ocean', 'olive', 'onyx', 'opal',
        'orange', 'orchid', 'otter', 'oxide', 'paddle', 'palm', 'pastel', 'peach',
        'pebble', 'pepper', 'pewter', 'pigeon', 'pillow', 'pine', 'planet', 'plum',
        'pollen', 'poplar', 'prairie', 'pumpkin', 'quartz', 'quiet', 'quill', 'rabbit',
        'radiant', 'rain', 'raven', 'ribbon', 'ridge', 'river', 'robin', 'rocket',
        'rosemary', 'ruby', 'saffron', 'sage', 'sailor', 'salmon', 'sandal', 'sapphire',
        'satin', 'scarlet', 'seed', 'shadow', 'shell', 'silver', 'sky', 'slate',
        'smooth', 'solar', 'sparrow', 'spice', 'spruce', 'stone', 'storm', 'summer',
        'sunset', 'sylvan', 'table', 'tangerine', 'teal', 'thunder', 'timber', 'topaz',
        'tulip', 'tundra', 'valley', 'velvet', 'violet', 'walnut', 'willow', 'winter',
    ];

    public static function generate(int $words = 1, int $digits = 2): string
    {
        $parts = [];

        for ($i = 0; $i < $words; $i++) {
            // random_int is the CSPRNG — array_rand/rand are not safe for credentials.
            $parts[] = self::WORDS[random_int(0, count(self::WORDS) - 1)];
        }

        $max = (10 ** $digits) - 1;
        $parts[] = str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }
}
