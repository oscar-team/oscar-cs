<div>
    @json($items)
    @json($model->toArray())
    @json(optional($user)->only(['id', 'name']))
    @json(foo($bar))
</div>
