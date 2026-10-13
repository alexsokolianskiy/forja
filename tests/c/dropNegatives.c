#include "forja_rt.h"

static fj_val {{symbol}}_impl(fj_ctx *ctx, fj_val map, fj_val limit)
{
    if (map.type != FJ_ARR || (limit.type != FJ_NULL && limit.type != FJ_INT)) {
        fj_fail(ctx);
    }
    /* foreach iterates a snapshot while the body unsets from the array itself. */
    fj_arr *snapshot = fj_arr_clone(ctx, map.u.a);
    size_t it = 0;
    fj_val key, *value;
    while (fj_next(snapshot, &it, &key, &value)) {
        if (value->type != FJ_INT) {
            fj_fail(ctx);
        }
        if (value->u.i < 0 || (limit.type == FJ_INT && value->u.i > limit.u.i)) {
            if (key.type == FJ_INT) {
                fj_unset_i(ctx, map.u.a, key.u.i);
            } else {
                fj_unset_s(ctx, map.u.a, key.u.s);
            }
        }
    }
    fj_push(ctx, map.u.a, fj_int((int64_t) fj_count(map.u.a)));
    return map;
}
