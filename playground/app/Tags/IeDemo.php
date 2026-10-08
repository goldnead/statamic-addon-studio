<?php

namespace App\Tags;

use Goldnead\StatamicInlineEdit\InlineEdit;
use Statamic\Facades\Entry;
use Statamic\Tags\Tags;

/**
 * Antlers access to the PHP helpers of statamic-inline-edit, for the demo page
 * `resources/views/inline_edit_demo.antlers.html`.
 *
 * The `editable` tag covers a whole field. A grid cell by row id, a picture
 * cell with its alt text, and a window over several values are only reachable
 * from PHP (`InlineEdit::cell()`, `InlineEdit::popup()`), which is what a site
 * with a component-driven front end does. This tag lets a plain template show
 * the same thing, so a visitor sees every kind of marker on one page.
 *
 * Every method returns markup for the current entry. For a visitor, or for
 * anyone who may not edit it, the marker methods return an empty string: the
 * element is byte for byte what it would have been without them.
 *
 *     <h3 {{ ie_demo:cell row="price" column="text" label="Price" }}>{{ ie_demo:value row="price" }}</h3>
 */
class IeDemo extends Tags
{
    protected static $handle = 'ie_demo';

    /**
     * Attributes for one grid cell, to be written inside a start tag.
     *
     *     {{ ie_demo:cell row="lead" source="true" }}
     *     {{ ie_demo:cell row="pic" column="value" image="true" alt="pic-alt" }}
     *     {{ ie_demo:cell row="quote" popup="true" }}
     */
    public function cell(): string
    {
        $entry = $this->entry();

        if ($entry === null) {
            return '';
        }

        return $this->attributes(InlineEdit::cell(
            $entry,
            (string) $this->params->get('grid', 'lines'),
            (string) $this->params->get('row'),
            (string) $this->params->get('column', 'text'),
            $this->params->get('label'),
            image: $this->params->bool('image'),
            alt: $this->params->get('alt'),
            source: $this->params->bool('source'),
            popup: $this->params->bool('popup'),
        ));
    }

    /**
     * Attributes for an element that shows several stored values at once.
     *
     * `cells` are grid cells as `rowId:column:Label`, `fields` are plain
     * field handles as `handle:Label`, both separated by `|`.
     *
     *     {{ ie_demo:popup cells="h1:text:Line 1|h2:text:Line 2" label="Hero headline" }}
     *     {{ ie_demo:popup fields="testimonial_quote:Quote|testimonial_author:Name" }}
     */
    public function popup(): string
    {
        $entry = $this->entry();

        if ($entry === null) {
            return '';
        }

        $grid = (string) $this->params->get('grid', 'lines');
        $addresses = [];

        foreach (array_filter(explode('|', (string) $this->params->get('cells', ''))) as $spec) {
            [$row, $column, $label] = array_pad(explode(':', $spec, 3), 3, null);
            $addresses[$grid.'.'.$row.'.'.$column] = $label ?? $row;
        }

        foreach (array_filter(explode('|', (string) $this->params->get('fields', ''))) as $spec) {
            [$handle, $label] = array_pad(explode(':', $spec, 2), 2, null);
            $addresses[$handle] = $label ?? $handle;
        }

        return $this->attributes(InlineEdit::popup($entry, $addresses, $this->params->get('label')));
    }

    /**
     * The stored value of one cell. `emphasis="true"` draws `*word*` as
     * emphasis, `quotes="true"` wraps the value in typographic quotation
     * marks: the two ways a page shows a value changed.
     */
    public function value(): string
    {
        $text = $this->cellValue(
            (string) $this->params->get('row'),
            (string) $this->params->get('column', 'text'),
        );

        $text = e($text);

        if ($this->params->bool('emphasis')) {
            $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        }

        if ($this->params->bool('quotes')) {
            $text = '&ldquo;'.$text.'&rdquo;';
        }

        return $text;
    }

    /**
     * A single shown value, escaped, for use inside an attribute.
     */
    public function raw(): string
    {
        return e($this->cellValue(
            (string) $this->params->get('row'),
            (string) $this->params->get('column', 'text'),
        ));
    }

    protected function cellValue(string $row, string $column): string
    {
        $grid = (string) $this->params->get('grid', 'lines');

        $rows = $this->context->raw($grid);

        foreach (is_array($rows) ? $rows : [] as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $row) {
                $value = $candidate[$column] ?? '';

                return is_string($value) ? $value : '';
            }
        }

        return '';
    }

    protected function entry(): mixed
    {
        $id = $this->context->raw('id');

        return is_string($id) ? Entry::find($id) : null;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    protected function attributes(array $attributes): string
    {
        return collect($attributes)
            ->map(fn ($v, $k) => $k.'="'.e($v).'"')
            ->implode(' ');
    }
}
