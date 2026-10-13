#include "forja_rt.h"

static fj_val {{symbol}}_impl(fj_ctx *ctx, fj_val value, fj_val default_)
{
    (void) ctx;
    return value.type == FJ_NULL ? default_ : value;
}
