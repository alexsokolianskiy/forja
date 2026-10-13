#include <stdint.h>
#include <stddef.h>

/* Deliberately dereferences NULL: stands in for a model-written memory bug. */
int64_t {{symbol}}(int64_t n, int32_t *accel_err)
{
    (void) accel_err;
    int64_t *volatile p = NULL;
    return *p + n;
}
