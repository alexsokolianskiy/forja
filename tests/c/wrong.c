#include <stdint.h>

/* Deliberately wrong for one input: stands in for a subtly incorrect translation. */
int64_t {{symbol}}(int64_t n, int32_t *forja_err)
{
    (void) forja_err;
    return n * 2 + (n == 77);
}
