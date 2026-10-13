#include "forja_rt.h"

static int64_t {{symbol}}_impl(fj_ctx *ctx, fj_val n)
{
    if (n.type != FJ_INT) {
        fj_fail(ctx); /* (int) of anything else follows PHP's conversion rules: leave it to PHP */
    }
    return n.u.i;
}
